<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Http\Request;

/**
 * The company-wide leave register for Admin and HR.
 *
 * Read-only in this phase: it answers "who is off, when, and on what" and is the
 * screen the approval actions attach to in Phase 4.6.
 */
class LeaveController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $requests = LeaveRequest::with(['employee.department', 'leaveType', 'approver'])
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

        $stats = [
            'pending'    => LeaveRequest::where('company_id', $companyId)->pending()->count(),
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
}
