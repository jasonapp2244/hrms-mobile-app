<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clocking in and out from the phone, and looking back at what was recorded.
 *
 * Every punch goes through AttendanceService exactly as the web portal's button
 * does, so the app cannot end up with different rules: server-authoritative
 * time, the same duplicate-scan cooldown, the same roster-aware shift for
 * judging lateness, and GPS recorded but never used to block anyone.
 */
class AttendanceController extends ApiController
{
    /** The furthest back one history call will reach. */
    public const MAX_HISTORY_DAYS = 92;

    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    /**
     * Punch in or out. Which one it is, is decided by the server from what was
     * already recorded — the app never says, so a stale screen cannot post the
     * wrong one.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $employee = $this->employee();

        if ($this->attendance->recentlyScanned($employee)) {
            return $this->fail(
                'duplicate_scan',
                'Already recorded moments ago. Please wait a minute.',
                429,
            );
        }

        // Tag the punch against the employee's own office, falling back to the
        // company's first — remote staff may not sit at a fixed one.
        $office = $employee->office
            ?? Office::where('company_id', $employee->company_id)->orderBy('id')->first();

        if (! $office) {
            return $this->fail(
                'no_office',
                'No office is set up for your company yet. Please contact HR.',
                422,
            );
        }

        try {
            $result = $this->attendance->record($employee, $office, [
                'source'     => 'mobile',
                'latitude'   => $data['latitude'] ?? null,
                'longitude'  => $data['longitude'] ?? null,
                'ip_address' => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            // Geofence enforcement (A4.16). Given its own error code rather than
            // a generic refusal so the app can say "move closer" instead of
            // "something went wrong" — the one message the person can act on.
            return $this->fail('outside_geofence', $e->getMessage(), 422);
        }

        $log = $result['log'];

        return $this->ok([
            'punch' => $this->punchPayload($log, $this->timezone($employee)),
            // What the button should say next, so the app does not have to
            // re-fetch the day just to relabel itself.
            'next_action' => $result['type'] === 'in' ? 'out' : 'in',
            'message' => sprintf(
                'You clocked %s at %s.',
                strtoupper($result['type']),
                $log->scanned_at->format('h:i A'),
            ),
        ]);
    }

    /**
     * Everything the home screen shows: where the day stands, and why the
     * employee may not be expected in at all.
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('office', 'department');
        $timezone = $this->timezone($employee);
        $now      = now($timezone);

        // The day a punch made right now would count against — on a night shift
        // that is still yesterday, and the screen has to agree with the record.
        $date = $this->attendance->workDateFor($employee, $now);

        $logs = $this->logsFor($employee, $date);
        $last = $logs->last();

        return $this->ok([
            'date'        => $date,
            'server_time' => $now->toIso8601String(),
            'timezone'    => $timezone,
            'next_action' => ($last && $last->type === 'in') ? 'out' : 'in',
            // False only while the cooldown is running, so the app can grey the
            // button out rather than let a tap fail.
            'can_check'      => ! $this->attendance->recentlyScanned($employee),
            'punches' => $logs->map(fn ($log) => $this->punchPayload($log, $timezone))->values(),
            // The comparison point has to be stated in the same frame the
            // punches are stored in, or the tz offset is counted as hours worked.
            'worked_minutes' => $this->attendance->workedMinutes(
                $logs, $this->attendance->wallClock($now),
            ),
            // Open means the clock is still running on the number above.
            'is_clocked_in' => (bool) ($last && $last->type === 'in'),
        ] + $this->dayContext($employee, $date));
    }

    /**
     * The attendance list, one row per day rather than per punch.
     *
     * A raw log feed is not what anyone wants to read on a phone: the question
     * is "did I make it in, and when", which is a day-shaped answer.
     */
    public function history(Request $request): JsonResponse
    {
        $employee = $this->employee();
        $timezone = $this->timezone($employee);
        $today    = now($timezone)->toDateString();

        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d',
        ]);

        $to   = $data['to'] ?? $today;
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->toDateString();

        // A day that has not happened yet has no attendance to report, and
        // calling it an absence would be a lie the employee cannot answer.
        if ($to > $today) {
            $to = $today;
        }

        if ($from > $to) {
            return $this->fail('invalid_range', 'The start date must fall on or before the end date.');
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_HISTORY_DAYS) {
            return $this->fail('range_too_large', sprintf(
                'Ask for at most %d days at a time.', self::MAX_HISTORY_DAYS,
            ));
        }

        $logs = AttendanceLog::with('office')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

        // The calendar facts for the whole window in one pass, rather than a
        // query per day.
        $working  = array_flip($this->leave->workingDatesBetween($employee->company, $from, $to));
        $holidays = $employee->company
            ? Holiday::namedBetween($employee->company_id, $from, $to)
            : [];
        $onLeave  = array_flip(
            $this->leave->leaveDatesByEmployee($employee->company_id, $from, $to)[$employee->id] ?? []
        );
        $daysOff = $employee->shiftAssignments()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->where('is_day_off', true)
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->flip();

        $days = [];

        for ($day = Carbon::parse($to); $day->gte(Carbon::parse($from)); $day->subDay()) {
            $date      = $day->toDateString();
            $dayLogs   = $logs->get($date, collect());
            $firstIn   = $dayLogs->firstWhere('type', 'in');
            $lastOut   = $dayLogs->last(fn ($log) => $log->type === 'out');

            $days[] = [
                'date'    => $date,
                'weekday' => $day->format('D'),
                'status'  => $this->attendance->dayStatus(
                    $dayLogs->isNotEmpty(),
                    isset($onLeave[$date]), isset($holidays[$date]),
                    $daysOff->has($date), isset($working[$date]),
                ),
                'late'           => $firstIn?->status === 'late',
                'first_in'       => $firstIn ? $this->attendance->wallClock($firstIn->scanned_at, $timezone)->toIso8601String() : null,
                'last_out'       => $lastOut ? $this->attendance->wallClock($lastOut->scanned_at, $timezone)->toIso8601String() : null,
                'worked_minutes' => $this->attendance->workedMinutes($dayLogs),
                'punches'        => $dayLogs->count(),
                'holiday'        => $holidays[$date] ?? null,
            ];
        }

        $rows = collect($days);

        return $this->ok([
            'from'  => $from,
            'to'    => $to,
            'days'  => $days,
            'totals' => [
                'present_days'   => $rows->where('status', 'present')->count(),
                'late_days'      => $rows->where('late', true)->count(),
                'leave_days'     => $rows->where('status', 'leave')->count(),
                'absent_days'    => $rows->where('status', 'absent')->count(),
                'worked_minutes' => $rows->sum('worked_minutes'),
            ],
        ]);
    }


    /** Why the employee is or is not expected in on a date. */
    protected function dayContext(Employee $employee, string $date): array
    {
        $shift    = $employee->shiftOn($date);
        $leave    = $employee->leaveOn($date);
        $holidays = $employee->company
            ? Holiday::namedBetween($employee->company_id, $date, $date)
            : [];

        return [
            'shift' => $shift ? [
                'id'                 => $shift->id,
                'name'               => $shift->name,
                'start_time'         => $shift->start_time,
                'end_time'           => $shift->end_time,
                'late_grace_minutes' => (int) $shift->late_grace_minutes,
                'crosses_midnight'   => $shift->crossesMidnight(),
            ] : null,
            'is_day_off' => $employee->isRosteredOff($date),
            'holiday'    => $holidays[$date] ?? null,
            'leave'      => $leave ? [
                'id'          => $leave->id,
                'type'        => $leave->leaveType?->name,
                'start_date'  => $leave->start_date->toDateString(),
                'end_date'    => $leave->end_date->toDateString(),
                'is_half_day' => (bool) $leave->is_half_day,
            ] : null,
        ];
    }

    /** A day's punches in order. */
    protected function logsFor(Employee $employee, string $date)
    {
        return AttendanceLog::with('office')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get();
    }

    protected function punchPayload(AttendanceLog $log, string $timezone): array
    {
        // Stamped with the company's offset rather than served bare. A phone
        // parses an ISO string and renders it in its own zone, so a punch sent
        // without the right offset would show up hours out for the person who
        // made it.
        $at = $this->attendance->wallClock($log->scanned_at, $timezone);

        return [
            'id'         => $log->id,
            'type'       => $log->type,
            'status'     => $log->status,
            'scanned_at' => $at->toIso8601String(),
            'time'       => $at->format('h:i A'),
            'office'     => $log->office?->name,
            'source'     => $log->source,
        ];
    }

}
