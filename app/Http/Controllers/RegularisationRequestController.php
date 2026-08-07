<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use Carbon\Carbon;
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

        $timezone = $employee->company?->tz() ?? config('app.timezone');
        $at = Carbon::parse($data['requested_at'], $timezone);

        if ($at->isFuture()) {
            return back()->withInput()->withErrors([
                'requested_at' => 'You cannot ask for a correction to a time that has not happened yet.',
            ]);
        }

        // A challenged punch must be one of this employee's own. Checked rather
        // than trusted: the id arrives from a form field anyone can retype.
        $challenged = null;

        if (! empty($data['attendance_log_id'])) {
            $challenged = AttendanceLog::where('employee_id', $employee->id)
                ->find($data['attendance_log_id']);

            if (! $challenged) {
                return back()->withInput()->withErrors([
                    'attendance_log_id' => 'That punch is not on your record.',
                ]);
            }
        }

        // One open request per punch, or per date-and-type where there is no
        // punch. Without this a double submit produces two approvals and two
        // corrections for the same problem.
        $duplicate = AttendanceRegularisation::where('employee_id', $employee->id)
            ->pending()
            ->when(
                $challenged,
                fn ($q) => $q->where('attendance_log_id', $challenged->id),
                fn ($q) => $q->whereNull('attendance_log_id')
                    ->whereDate('work_date', $at->toDateString())
                    ->where('type', $data['type']),
            )
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors([
                'reason' => 'You already have a request waiting on this. Wait for it to be decided first.',
            ]);
        }

        AttendanceRegularisation::create([
            'company_id'        => $employee->company_id,
            'employee_id'       => $employee->id,
            'attendance_log_id' => $challenged?->id,
            'office_id'         => $challenged?->office_id ?? $employee->office_id,
            'work_date'         => $at->toDateString(),
            'type'              => $data['type'],
            'requested_at'      => $at,
            'reason'            => $data['reason'],
        ]);

        return back()->with('success', 'Request submitted. HR will review it.');
    }

    /** Withdraw a request that has not been decided yet. */
    public function cancel(AttendanceRegularisation $regularisation)
    {
        $employee = $this->currentEmployee();

        abort_unless($regularisation->employee_id === $employee->id, 404);

        if (! $regularisation->isPending()) {
            return back()->withErrors(['status' => 'That request has already been decided.']);
        }

        $regularisation->update(['status' => 'cancelled']);

        return back()->with('success', 'Request withdrawn.');
    }
}
