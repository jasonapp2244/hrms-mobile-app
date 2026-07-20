<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use Illuminate\Http\Request;

class EmployeePortalController extends Controller
{
    public function __construct(
        protected QrTokenService $qr,
        protected AttendanceService $attendance,
    ) {}

    /** Resolve the employee record for the currently logged-in user. */
    protected function currentEmployee()
    {
        $employee = auth()->user()->employee;
        abort_unless($employee, 403, 'No employee record is linked to this account.');
        return $employee;
    }

    /** Employee home: today's status, check in/out, and their own attendance list. */
    public function dashboard()
    {
        $employee = $this->currentEmployee()->load('office', 'department', 'designation');

        $today = now($employee->company->timezone ?? config('app.timezone'))->toDateString();

        $todayLogs = AttendanceLog::where('employee_id', $employee->id)
            ->where('work_date', $today)
            ->orderBy('scanned_at')
            ->get();

        $lastToday = $todayLogs->last();
        // If the last punch today was an "in", the next action is to check OUT.
        $nextAction = ($lastToday && $lastToday->type === 'in') ? 'out' : 'in';

        $logs = AttendanceLog::with('office')
            ->where('employee_id', $employee->id)
            ->latest('scanned_at')
            ->paginate(15);

        return view('employee.dashboard', compact('employee', 'todayLogs', 'nextAction', 'logs'));
    }

    /**
     * AJAX: one-tap button check in/out.
     *
     * Works from any device (mobile or PC) and any location — the button is the
     * primary flow for office, WFH and hybrid staff alike. GPS is captured when
     * the browser offers it (free, no API key) purely as a record for HR; it is
     * never used to block a punch. Server time is authoritative.
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $employee = $this->currentEmployee();

        if ($this->attendance->recentlyScanned($employee)) {
            return response()->json(['ok' => false, 'message' => 'Already recorded moments ago. Please wait a minute.'], 429);
        }

        // Tag the punch against the employee's assigned office, falling back to
        // the company's first office (WFH staff may not sit at a fixed office).
        $office = $employee->office
            ?? Office::where('company_id', $employee->company_id)->orderBy('id')->first();

        if (! $office) {
            return response()->json(['ok' => false, 'message' => 'No office is set up for your company yet. Please contact HR.'], 422);
        }

        $result = $this->attendance->record($employee, $office, [
            'source'     => 'button',
            'latitude'   => $data['latitude'] ?? null,
            'longitude'  => $data['longitude'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'ok'      => true,
            'type'    => $result['type'],
            'status'  => $result['status'],
            'time'    => $result['log']->scanned_at->format('h:i A'),
            'message' => sprintf('You clocked %s (%s) at %s.',
                strtoupper($result['type']), $result['status'], $result['log']->scanned_at->format('h:i A')),
        ]);
    }

    /** AJAX: employee scans the office QR to record their own clock in/out. */
    public function scan(Request $request)
    {
        $data = $request->validate([
            'payload'   => 'required|string',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $employee = $this->currentEmployee();

        $parsed = $this->qr->parsePayload($data['payload']);
        if (! $parsed) {
            return response()->json(['ok' => false, 'message' => 'Invalid QR code.'], 422);
        }

        $office = Office::find($parsed['office_id']);
        if (! $office || $office->company_id !== $employee->company_id) {
            return response()->json(['ok' => false, 'message' => 'This QR code is not for your company.'], 404);
        }

        if (! $this->qr->validate($office, $parsed['token'])) {
            return response()->json(['ok' => false, 'message' => 'QR code expired. Please scan the live code again.'], 422);
        }

        if ($this->attendance->recentlyScanned($employee)) {
            return response()->json(['ok' => false, 'message' => 'Already scanned moments ago. Please wait a minute.'], 429);
        }

        $result = $this->attendance->record($employee, $office, [
            'source'     => 'employee',
            'latitude'   => $data['latitude'] ?? null,
            'longitude'  => $data['longitude'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'ok'       => true,
            'type'     => $result['type'],
            'status'   => $result['status'],
            'office'   => $office->name,
            'time'     => $result['log']->scanned_at->format('h:i A'),
            'message'  => sprintf('You clocked %s (%s) at %s at %s.',
                strtoupper($result['type']), $result['status'], $result['log']->scanned_at->format('h:i A'), $office->name),
        ]);
    }
}
