<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * The employee's own leave: balances, apply, history, withdraw.
 *
 * Lives in the self-service portal, so every query is scoped to the signed-in
 * user's employee record and nothing here can reach another person's leave.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    /** Resolve the employee record for the currently logged-in user. */
    protected function currentEmployee()
    {
        $employee = auth()->user()->employee;
        abort_unless($employee, 403, 'No employee record is linked to this account.');

        return $employee;
    }

    public function index()
    {
        $employee = $this->currentEmployee()->load('company');

        $balances = $this->leave->balanceSummary($employee);

        $requests = $employee->leaveRequests()
            // employee is eager loaded because the stage label reads its
            // manager_id — without it every row would fire its own query.
            ->with('leaveType', 'approver', 'employee')
            ->latest('start_date')
            ->paginate(10);

        // Only types that are still open for new requests appear in the form —
        // an inactive type keeps its history but cannot be booked again.
        $types = $balances->pluck('type');

        return view('employee.leave', compact('employee', 'balances', 'requests', 'types'));
    }

    public function store(Request $request)
    {
        $employee = $this->currentEmployee()->load('company');

        $data = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date'    => 'required|date',
            // Bounded so a typo like the year 2999 cannot walk the day loop for
            // a million iterations before the balance check refuses it.
            'end_date'      => 'required|date|after_or_equal:start_date|before_or_equal:' . now()->addYears(2)->toDateString(),
            'half_day_period' => 'nullable|in:first_half,second_half',
            'reason'        => 'nullable|string|max:1000',
        ], [
            'end_date.before_or_equal' => 'Leave cannot be booked more than two years ahead.',
        ]);

        $data['is_half_day'] = $request->boolean('is_half_day');

        // Business rules (overlap, balance, working days) throw ValidationException
        // from the service and land back on the form with the offending field.
        $leaveRequest = $this->leave->submit($employee, $data);

        return redirect()->route('employee.leave.index')->with('success',
            $leaveRequest->status === 'approved'
                ? 'Leave approved — this type does not need sign-off.'
                : 'Leave request submitted. You will be notified once it is reviewed.');
    }

    public function cancel(LeaveRequest $leaveRequest)
    {
        $employee = $this->currentEmployee();

        // Ownership, not just permission: the route is reachable by every
        // employee, so the record has to be checked against this one.
        abort_unless($leaveRequest->employee_id === $employee->id, 403);

        $this->leave->cancel($leaveRequest);

        return back()->with('success', 'Leave request withdrawn.');
    }
}
