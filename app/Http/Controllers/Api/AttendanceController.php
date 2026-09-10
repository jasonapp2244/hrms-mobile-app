<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Support\Clock;
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
                __('attendance.duplicate_scan'),
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
                __('attendance.no_office'),
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
            // Two messages rather than one with the direction dropped into it:
            // "You clocked :type at" cannot be translated without knowing what
            // the type is, and languages do not agree on where it goes.
            'message' => $result['type'] === 'in'
                ? __('attendance.clocked_in', ['time' => Clock::time($log->scanned_at)])
                : __('attendance.clocked_out', ['time' => Clock::time($log->scanned_at)]),
        ]);
    }

    /** Most punches one sync call will take. A day of tapping, not a month. */
    public const MAX_SYNC_PUNCHES = 50;

    /**
     * Deliver punches made with no signal (B2.4).
     *
     * A batch rather than one call per punch, because the moment this runs is
     * the moment the connection is worst: a queue of four punches over a link
     * that drops is four chances to fail rather than one.
     *
     * **Partial success is the normal case, so the response is per punch.**
     * One refused punch must not throw away three good ones — the app has to
     * know exactly which entries to drop from its queue and which to keep. A
     * single ok/failed for the batch would leave it guessing, and guessing here
     * means either losing somebody's hours or punching them in twice.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'punches'                => 'required|array|min:1|max:' . self::MAX_SYNC_PUNCHES,
            'punches.*.occurred_at'  => 'required|date',
            'punches.*.latitude'     => 'nullable|numeric|between:-90,90',
            'punches.*.longitude'    => 'nullable|numeric|between:-180,180',
        ], [
            'punches.max' => 'Send at most ' . self::MAX_SYNC_PUNCHES . ' punches at a time.',
        ]);

        $employee = $this->employee();
        $timezone = $this->timezone($employee);

        $office = $employee->office
            ?? Office::where('company_id', $employee->company_id)->orderBy('id')->first();

        if (! $office) {
            return $this->fail(
                'no_office',
                __('attendance.no_office'),
                422,
            );
        }

        // Oldest first, whatever order the queue sent them in. Each punch's
        // direction is inferred from what precedes it, so applying them out of
        // order would have a later one decide before the earlier one existed.
        $queued = collect($data['punches'])
            ->map(fn (array $p) => $p + ['_at' => Carbon::parse($p['occurred_at'], $timezone)])
            ->sortBy('_at')
            ->values();

        $results = [];

        foreach ($queued as $punch) {
            try {
                $result = $this->attendance->recordQueued($employee, $office, $punch['_at'], [
                    'latitude'   => $punch['latitude'] ?? null,
                    'longitude'  => $punch['longitude'] ?? null,
                    'ip_address' => $request->ip(),
                ]);

                $results[] = [
                    'occurred_at' => $punch['occurred_at'],
                    // accepted | duplicate — both mean "stop retrying this one".
                    'result' => $result['duplicate'] ? 'duplicate' : 'accepted',
                    'punch'  => $this->punchPayload($result['log'], $timezone),
                ];
            } catch (\RuntimeException $e) {
                // Refused for a reason that will not change on a retry — too
                // old, dated in the future, outside the fence. The app drops
                // these from the queue and tells the person why; keeping them
                // would retry for ever.
                $results[] = [
                    'occurred_at' => $punch['occurred_at'],
                    'result'      => 'refused',
                    'message'     => $e->getMessage(),
                ];
            }
        }

        $counts = collect($results)->countBy('result');

        return $this->ok([
            'results'  => $results,
            'accepted' => $counts['accepted'] ?? 0,
            'duplicate' => $counts['duplicate'] ?? 0,
            'refused'  => $counts['refused'] ?? 0,
        ]);
    }

    /**
     * Start or end a break (B2.6).
     *
     * A4.15 built this months ago and the web portal's break button has called
     * `recordBreak` ever since; the API simply never exposed it, so the app
     * could not offer the button at all. This adds nothing to the rules — the
     * service still decides start-vs-end from the day's punches, so a stale
     * screen cannot post the wrong one, exactly as `check` cannot.
     *
     * Deliberately a separate endpoint rather than a `type` on `check`: the two
     * refuse for different reasons and the app has to tell them apart. A break
     * is refused when the day is not in a state for one; a punch never is.
     */
    public function break(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $employee = $this->employee();

        if ($this->attendance->recentlyScanned($employee)) {
            return $this->fail(
                'duplicate_scan',
                __('attendance.duplicate_scan'),
                429,
            );
        }

        $office = $employee->office
            ?? Office::where('company_id', $employee->company_id)->orderBy('id')->first();

        if (! $office) {
            return $this->fail(
                'no_office',
                __('attendance.no_office'),
                422,
            );
        }

        try {
            $result = $this->attendance->recordBreak($employee, $office, [
                'source'     => 'mobile',
                'latitude'   => $data['latitude'] ?? null,
                'longitude'  => $data['longitude'] ?? null,
                'ip_address' => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            // Its own code, not the geofence's: this one means "you are not
            // clocked in", which the app answers by refreshing the day rather
            // than by telling somebody to move closer to the office.
            return $this->fail('break_not_available', $e->getMessage(), 422);
        }

        $log     = $result['log'];
        $started = $result['type'] === 'break_start';
        $at      = $this->attendance->wallClock($log->scanned_at, $this->timezone($employee));

        return $this->ok([
            'punch'    => $this->punchPayload($log, $this->timezone($employee)),
            'on_break' => $started,
            // What the break button should say next. Named apart from
            // `next_action`, which belongs to the in/out button — one screen
            // carries both and they move independently.
            'next_break_action' => $started ? 'end' : 'start',
            'message' => $started
                ? __('attendance.break_started', ['time' => Clock::time($at)])
                : __('attendance.break_ended', ['time' => Clock::time($at)]),
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

        // Not `$logs->last()`. Reading the last punch treats a break as the end
        // of the day: `break_end` is neither 'in' nor 'out', so the screen would
        // offer "Check In" to somebody who never left and start a second
        // attendance stretch when they took it. `breakState` is the one place
        // that knows all four types mean, between them, one of two states — the
        // same reading the live board uses. It costs a second query on this
        // endpoint, which is worth more than two definitions of "clocked in".
        $state    = $this->attendance->breakState($employee, $date);
        $cooldown = $this->attendance->recentlyScanned($employee);

        return $this->ok([
            'date'        => $date,
            'server_time' => $now->toIso8601String(),
            'timezone'    => $timezone,
            'next_action' => $state['clocked_in'] ? 'out' : 'in',
            // False only while the cooldown is running, so the app can grey the
            // button out rather than let a tap fail.
            'can_check'      => ! $cooldown,
            // The break button's own three facts. It is offered only on the
            // clock, because that is the only state `recordBreak` accepts —
            // better greyed out than refused after the tap.
            'on_break'          => $state['on_break'],
            'break_started_at'  => $state['break_started_at']
                ? $this->attendance->wallClock($state['break_started_at'], $timezone)->toIso8601String()
                : null,
            'can_break'         => $state['clocked_in'] && ! $cooldown,
            'next_break_action' => $state['on_break'] ? 'end' : 'start',
            'punches' => $logs->map(fn ($log) => $this->punchPayload($log, $timezone))->values(),
            // The comparison point has to be stated in the same frame the
            // punches are stored in, or the tz offset is counted as hours worked.
            'worked_minutes' => $this->attendance->workedMinutes(
                $logs, $this->attendance->wallClock($now),
            ),
            // Open means the clock is still running on the number above. True
            // through a break as well: the person has not gone home, and
            // workedMinutes has already subtracted the break itself.
            'is_clocked_in' => $state['clocked_in'],
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
            return $this->fail('invalid_range', __('api.invalid_range'));
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_HISTORY_DAYS) {
            return $this->fail('range_too_large', __('api.range_too_large', [
                'days' => self::MAX_HISTORY_DAYS,
            ]));
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
            'time'       => Clock::time($at),
            'office'     => $log->office?->name,
            'source'     => $log->source,
        ];
    }

}
