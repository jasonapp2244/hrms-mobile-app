<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * A line manager's leave inbox, inside the self-service portal.
 *
 * Managers are staff who decide for their own team, not dashboard users — every
 * query here is scoped to their direct reports, and a request belonging to
 * anyone else is a 403 regardless of the approve-leave permission.
 */
class LeaveApprovalController extends Controller
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    protected function currentEmployee()
    {
        $employee = auth()->user()->employee;
        abort_unless($employee, 403, 'No employee record is linked to this account.');

        return $employee;
    }

    public function index()
    {
        $manager = $this->currentEmployee();
        $teamIds = $manager->subordinates()->pluck('id');

        $pending = LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('employee_id', $teamIds)
            ->pending()
            ->orderBy('start_date')
            ->get()
            // Only what this manager still has to act on. Anything already passed
            // up is waiting on HR and is shown in the decided list instead.
            ->filter(fn (LeaveRequest $r) => $r->isAwaitingManager())
            ->values();

        // Who else on the team is already off over the same dates. A manager
        // approving cover has to know that before saying yes, not after.
        $clashes = $pending->mapWithKeys(fn (LeaveRequest $r) => [
            $r->id => LeaveRequest::with('employee')
                ->whereIn('employee_id', $teamIds)
                ->where('employee_id', '!=', $r->employee_id)
                ->approved()
                ->overlapping($r->start_date->toDateString(), $r->end_date->toDateString())
                ->get(),
        ]);

        $decided = LeaveRequest::with(['employee', 'leaveType', 'approver'])
            ->whereIn('employee_id', $teamIds)
            ->where(fn ($q) => $q->whereNotNull('manager_approved_at')->orWhere('status', '!=', 'pending'))
            ->latest('updated_at')
            ->limit(15)
            ->get();

        return view('employee.approvals', compact('manager', 'pending', 'clashes', 'decided'));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authoriseTeamMember($leaveRequest);

        $this->leave->managerApprove(
            $leaveRequest,
            auth()->id(),
            $request->input('manager_note'),
        );

        return back()->with('success', sprintf(
            "%s's request has been passed to HR for final approval.",
            $leaveRequest->employee->first_name,
        ));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authoriseTeamMember($leaveRequest);

        $data = $request->validate(
            ['decision_note' => 'required|string|max:1000'],
            ['decision_note.required' => 'Please give a reason — the employee sees this.'],
        );

        $this->leave->reject($leaveRequest, auth()->id(), $data['decision_note']);

        return back()->with('success', 'Request rejected.');
    }

    /**
     * A manager may only act on their own direct reports, and never on their own
     * request — that decision belongs to their manager or to HR.
     */
    protected function authoriseTeamMember(LeaveRequest $leaveRequest): void
    {
        $manager = $this->currentEmployee();

        abort_if($leaveRequest->employee_id === $manager->id, 403,
            'You cannot decide on your own leave request.');

        abort_unless($leaveRequest->employee?->manager_id === $manager->id, 403);
    }
}
