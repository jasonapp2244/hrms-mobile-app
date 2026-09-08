<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Services\RegularisationService;
use Illuminate\Http\Request;

/**
 * The employee's own regularisation requests (A4.13), inside the portal.
 *
 * Every action is scoped to the signed-in employee here rather than by a
 * permission, for the same reason the rest of the portal is: holding the
 * employee role is the authorisation, and the thing being protected is that
 * one employee cannot see or touch another's record.
 */
class RegularisationRequestController extends Controller
{
    public function __construct(
        protected RegularisationService $regularisation,
    ) {}

    protected function currentEmployee(): Employee
    {
        $employee = auth()->user()->employee;
        abort_unless($employee, 403, 'No employee record is linked to this account.');

        return $employee;
    }

    public function index()
    {
        $employee = $this->currentEmployee();

        $requests = AttendanceRegularisation::with(['attendanceLog', 'createdLog'])
            ->where('employee_id', $employee->id)
            ->latest()
            ->paginate(15);

        // Offered for challenge: the employee's recent punches. Voided ones are
        // already excluded by the model's global scope — there is no sense in
        // disputing a reading that has been struck out.
        $recentPunches = AttendanceLog::where('employee_id', $employee->id)
            ->latest('scanned_at')
            ->limit(30)
            ->get();

        return view('employee.regularisations', compact('employee', 'requests', 'recentPunches'));
    }

    public function store(Request $request)
    {
        $employee = $this->currentEmployee();

        $data = $request->validate([
            'attendance_log_id' => ['nullable', 'integer'],
            'type'              => ['required', 'in:in,out'],
            'requested_at'      => ['required', 'date'],
            'reason'            => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // The rules that are not shape — future times, whose punch it is, one
        // open request per problem — live in the service, because the app posts
        // here too and a rule enforced in a form is a rule the app would not
        // have. The ValidationException it throws redirects back with the same
        // field keys this view already renders.
        $this->regularisation->submit($employee, $data);

        return back()->with('success', 'Request submitted. HR will review it.');
    }

    /** Withdraw a request that has not been decided yet. */
    public function cancel(AttendanceRegularisation $regularisation)
    {
        $employee = $this->currentEmployee();

        abort_unless($regularisation->employee_id === $employee->id, 404);

        $this->regularisation->cancel($regularisation);

        return back()->with('success', 'Request withdrawn.');
    }
}
