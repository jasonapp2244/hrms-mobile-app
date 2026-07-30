<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * The company-wide leave register for Admin and HR, and the final step of the
 * approval chain: a request reaches here once its manager has passed it on, or
 * straight away when the employee has no manager.
 */
class LeaveController extends Controller
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $requests = LeaveRequest::with(['employee.department', 'employee.manager', 'leaveType', 'approver', 'managerApprover'])
            ->where('company_id', $companyId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('leave_type_id'), fn ($q) => $q->where('leave_type_id', $request->leave_type_id))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee',
                fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->filled('q'), fn ($q) => $q->whereHas('employee', fn ($e) => $e
                ->where(fn ($w) => $w
                    ->where('first_name', 'like', "%{$request->q}%")
                    ->orWhere('last_name', 'like', "%{$request->q}%")
                    ->orWhere('employee_code', 'like', "%{$request->q}%"))))
            // Date filters match anything *overlapping* the window rather than
            // starting inside it, so a long absence still shows up in a month
            // it merely runs through.
            ->when($request->filled('from') || $request->filled('to'), fn ($q) => $q->overlapping(
                $request->input('from', '0001-01-01'),
                $request->input('to', '9999-12-31'),
            ))
            ->latest('start_date')
            ->paginate(20)
            ->withQueryString();

        $today = now()->toDateString();

        // Split the pending pile by who owns it. "Awaiting HR" is the queue this
        // screen can actually clear; the rest is sitting with line managers.
        $awaitingHr = LeaveRequest::where('company_id', $companyId)->pending()
            ->where(fn ($q) => $q->whereNotNull('manager_approved_at')
                ->orWhereHas('employee', fn ($e) => $e->whereNull('manager_id')))
            ->count();

        $stats = [
            'pending'    => LeaveRequest::where('company_id', $companyId)->pending()->count(),
            'awaiting_hr' => $awaitingHr,
            'on_leave'   => LeaveRequest::where('company_id', $companyId)->approved()
                                ->overlapping($today, $today)->count(),
            'upcoming'   => LeaveRequest::where('company_id', $companyId)->approved()
                                ->where('start_date', '>', $today)->count(),
            'this_month' => LeaveRequest::where('company_id', $companyId)->approved()
                                ->overlapping(now()->startOfMonth()->toDateString(),
                                              now()->endOfMonth()->toDateString())->count(),
        ];

        $types       = LeaveType::where('company_id', $companyId)->orderBy('name')->get();
        $departments = Department::where('company_id', $companyId)->orderBy('name')->get();

        return view('leave.index', compact('requests', 'stats', 'types', 'departments'));
    }

    /**
     * Final approval. HR/Admin can grant a request still sitting with its
     * manager — they outrank the step — but the register flags that clearly so
     * it is a deliberate override rather than an accident.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authoriseCompany($leaveRequest);

        $this->leave->approve($leaveRequest, auth()->id(), $request->input('decision_note'));

        return back()->with('success', sprintf(
            "%s's leave has been approved.",
            $leaveRequest->employee?->first_name ?? 'The',
        ));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authoriseCompany($leaveRequest);

        $data = $request->validate(
            ['decision_note' => 'required|string|max:1000'],
            ['decision_note.required' => 'Please give a reason — the employee sees this.'],
        );

        $this->leave->reject($leaveRequest, auth()->id(), $data['decision_note']);

        return back()->with('success', 'Request rejected.');
    }

    protected function authoriseCompany(LeaveRequest $leaveRequest): void
    {
        abort_unless($leaveRequest->company_id === $this->companyId(), 403);
    }
}
