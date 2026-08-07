<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    /**
     * Record a clock in/out event from a check-in button press.
     * Determines type (in/out) automatically and computes late/early status.
     *
     * @return array{log: AttendanceLog, type: string, status: string}
     */
    public function record(Employee $employee, Office $office, array $meta = []): array
    {
        $this->assertInsideGeofence($employee, $office, $meta);

        // Server-authoritative time — never trust the device clock
        $now = Carbon::now($office->company?->tz() ?? config('app.timezone'));
        $workDate = $this->workDateFor($employee, $now);

        // Determine whether this scan is a clock-in or clock-out:
        // if the last event today was an "in", this one is an "out", else "in".
        // whereDate, not a plain equality: work_date is cast to a date, so the
        // value written carries a 00:00:00 time on any engine without a real
        // DATE type. MySQL truncates it, SQLite does not — matching on the date
        // itself works on both.
        // Only in/out decide what comes next. Without the whereIn, a break taken
        // after checking in would leave 'break_end' as the last punch, and the
        // next press would be read as a fresh check-in — quietly starting a
        // second attendance stretch and losing the whole morning's pairing.
        $lastToday = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->whereIn('type', ['in', 'out'])
            ->orderByDesc('scanned_at')
            ->first();

        $type = ($lastToday && $lastToday->type === 'in') ? 'out' : 'in';

        $status = $this->determineStatus($type, $now, $employee, $workDate);

        $log = AttendanceLog::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'office_id'   => $office->id,
            'type'        => $type,
            'scanned_at'  => $now,
            'work_date'   => $workDate,
            'status'      => $status,
            'source'      => $meta['source'] ?? 'pwa',
            'latitude'    => $meta['latitude'] ?? null,
            'longitude'   => $meta['longitude'] ?? null,
            'ip_address'  => $meta['ip_address'] ?? null,
        ]);

        return ['log' => $log, 'type' => $type, 'status' => $status];
    }

    /**
     * Refuse a punch made outside the office, when the company asks for that.
     *
     * Off unless `enforce_geofence` is set, and off by default. The product's
     * premise is that people clock in from their own phone — the coordinates
     * are a record, not a gate — so switching this on is a deliberate change of
     * policy and not a default anybody backs into.
     *
     * Three things exempt a punch even when enforcement is on, and each is the
     * difference between a control and an obstacle:
     *
     *  - An employee whose work mode is WFH or hybrid. Fencing somebody to an
     *    office they were told not to come to is simply a bug.
     *  - An office with no coordinates set. There is no fence to be outside of,
     *    and refusing everybody at a branch whose address was never geocoded
     *    would take that branch offline.
     *  - A punch that arrived with no coordinates — services off, permission
     *    refused, no signal indoors. Refusing these would make the feature a
     *    denial-of-service on anybody whose phone cannot see the sky, and the
     *    missing location is itself recorded on the punch for HR to look at.
     *
     * @throws \RuntimeException when the punch is outside the fence
     */
    protected function assertInsideGeofence(Employee $employee, Office $office, array $meta): void
    {
        if (! $office->company?->policy('enforce_geofence')) {
            return;
        }

        if (in_array($employee->work_mode, ['wfh', 'hybrid'], true)) {
            return;
        }

        if ($office->latitude === null || $office->longitude === null) {
            return;
        }

        $lat = $meta['latitude'] ?? null;
        $lng = $meta['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            return;
        }

        $radius = (int) ($office->geofence_radius ?: 100);
        $distance = $this->metresBetween((float) $lat, (float) $lng, (float) $office->latitude, (float) $office->longitude);

        if ($distance > $radius) {
            throw new \RuntimeException(sprintf(
                'You appear to be %s from %s, which is outside the %dm check-in area. '
                . 'Move closer, or ask HR to record this punch for you.',
                $distance >= 1000
                    ? round($distance / 1000, 1) . 'km'
                    : round($distance) . 'm',
                $office->name,
                $radius,
            ));
        }
    }

    /**
     * Great-circle distance in metres.
     *
     * Haversine on a spherical earth. Accurate to a few metres over the
     * distances a geofence cares about, which is well inside the error of the
     * phone GPS producing the reading in the first place — a more exact
     * ellipsoidal formula would be false precision.
     */
    public function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Start or end a break (A4.15).
     *
     * Refuses rather than guesses when the day is not in a state where a break
     * makes sense — off the clock, or ending a break nobody started. A break
     * marker with no partner is worse than no marker at all: workedMinutes has
     * to discard it, so the employee sees a button that appeared to work and a
     * total that did not change.
     *
     * @return array{log: AttendanceLog, type: string}
     * @throws \RuntimeException when the break would not make sense
     */
    public function recordBreak(Employee $employee, Office $office, array $meta = []): array
    {
        $now = Carbon::now($office->company?->tz() ?? config('app.timezone'));
        $workDate = $this->workDateFor($employee, $now);

        $state = $this->breakState($employee, $workDate);

        if (! $state['clocked_in']) {
            throw new \RuntimeException('You need to be checked in before starting a break.');
        }

        $type = $state['on_break'] ? 'break_end' : 'break_start';

        $log = AttendanceLog::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'office_id'   => $office->id,
            'type'        => $type,
            'scanned_at'  => $now,
            'work_date'   => $workDate,
            // Breaks are not early or late — there is nothing to judge them
            // against, and reusing the in/out statuses here would put a
            // meaningless "late" badge on a lunch break.
            'status'      => 'ontime',
            'source'      => $meta['source'] ?? 'button',
            'latitude'    => $meta['latitude'] ?? null,
            'longitude'   => $meta['longitude'] ?? null,
            'ip_address'  => $meta['ip_address'] ?? null,
        ]);

        return ['log' => $log, 'type' => $type];
    }

    /**
     * Whether the employee is currently clocked in, and whether they are on a
     * break — the two facts the portal button needs to label itself.
     *
     * @return array{clocked_in: bool, on_break: bool, break_started_at: ?Carbon}
     */
    public function breakState(Employee $employee, ?string $workDate = null): array
    {
        $workDate ??= $this->workDateFor($employee, Carbon::now(
            $employee->company?->tz() ?? config('app.timezone'),
        ));

        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->orderBy('scanned_at')
            ->get();

        $clockedIn = false;
        $onBreak = false;
        $breakStartedAt = null;

        foreach ($logs as $log) {
            match ($log->type) {
                // Checking out ends any break that was still open: whatever the
                // employee did between the two, they are off the clock now.
                'in'          => $clockedIn = true,
                'out'         => [$clockedIn, $onBreak, $breakStartedAt] = [false, false, null],
                'break_start' => [$onBreak, $breakStartedAt] = [true, $log->scanned_at],
                'break_end'   => [$onBreak, $breakStartedAt] = [false, null],
                default       => null,
            };
        }

        return [
            'clocked_in'       => $clockedIn,
            'on_break'         => $onBreak,
            'break_started_at' => $breakStartedAt,
        ];
    }

    /**
     * Enter a punch on somebody's behalf, for a moment that has already passed.
     *
     * Everything HR needs when the button was not pressed: a forgotten
     * check-out, a badge that failed, a day worked off-site. It differs from
     * record() in three ways that all matter.
     *
     * The time is supplied rather than taken from the clock, so status is
     * judged against the shift as it was at that moment — a 09:40 entry keyed
     * in at 16:00 is 'late', not 'ontime'.
     *
     * The type is supplied rather than inferred. record() alternates in/out
     * from the last punch of the day, which is right for a button pressed in
     * sequence and wrong for a row being slotted into a gap.
     *
     * And the reason is required. A manual punch is the one kind a reader has
     * no other way to explain, so it carries its justification on the row and
     * into the trail.
     *
     * Who did it is not a parameter: AttendanceLog's created hook stamps the
     * signed-in user onto the audit event for every punch, however it arrived,
     * and a second way of saying the same thing could disagree with the first.
     */
    public function recordManual(
        Employee $employee,
        Office $office,
        string $type,
        Carbon $at,
        string $reason,
    ): AttendanceLog {
        $workDate = $this->workDateFor($employee, $at);

        return AttendanceLog::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'office_id'   => $office->id,
            'type'        => $type,
            'scanned_at'  => $at,
            'work_date'   => $workDate,
            'status'      => $this->determineStatus($type, $at, $employee, $workDate),
            // 'manual' is what tells every later reader — reports, exports, the
            // employee's own history — that a person keyed this in rather than
            // a device recording it.
            'source'      => 'manual',
            'ip_address'  => request()?->ip(),
            'notes'       => $reason,
        ]);
    }

    /**
     * What a day was, in one word.
     *
     * Turning up wins over every reason not to: somebody who books the day off
     * and comes in anyway worked, and the record has to say so. Absence is only
     * ever claimed for a day the company works, nobody planned off, and the
     * employee neither booked nor showed.
     *
     * Lives here rather than on a controller because two endpoints now answer
     * this question — a person's own history, and their manager's view of the
     * team — and two copies would eventually disagree about what "absent"
     * means for the same day.
     */
    public function dayStatus(
        bool $hasPunches,
        bool $onLeave,
        bool $isHoliday,
        bool $isDayOff,
        bool $isWorkingDay,
    ): string {
        return match (true) {
            $hasPunches     => 'present',
            $onLeave        => 'leave',
            $isHoliday      => 'holiday',
            $isDayOff       => 'day_off',
            ! $isWorkingDay => 'weekend',
            default         => 'absent',
        };
    }

    /**
     * Prevent accidental duplicate scans within a short cooldown.
     *
     * Compares against created_at (always stored/read in UTC) rather than
     * scanned_at — scanned_at is written in the company timezone, so comparing
     * it to now() would mismatch by the tz offset and skip the guard entirely.
     */
    public function recentlyScanned(Employee $employee, int $cooldownSeconds = 60): bool
    {
        $last = AttendanceLog::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->first();

        return $last && $last->created_at->diffInSeconds(now()) < $cooldownSeconds;
    }

    /**
     * The day a punch is counted against.
     *
     * Normally today. On a shift that crosses midnight it is the day the shift
     * started, so a 22:00 clock-in and the 06:00 clock-out that ends the same
     * stretch of work share a work_date instead of being split across two — a
     * split that would break in/out pairing and leave the night worker looking
     * absent on one day and half-present on the next.
     *
     * Public because the screens have to agree with the record: a night worker
     * opening the app at 02:00 must be shown the day their punch would land on,
     * not the calendar date on their phone.
     */
    public function workDateFor(Employee $employee, Carbon $now): string
    {
        if ($now->hour >= Shift::NIGHT_CUTOFF_HOUR) {
            return $now->toDateString();
        }

        // Before noon, the question is whether *yesterday's* shift was still
        // running — which is yesterday's roster entry, not today's. On a
        // rotation the two are routinely different shifts.
        $yesterday = $now->copy()->subDay()->toDateString();
        $shift = $employee->shiftOn($yesterday);

        return $shift && $shift->crossesMidnight() ? $yesterday : $now->toDateString();
    }

    /**
     * Status is measured against the shift the employee actually works — their
     * own if one is set for them, otherwise their department's. On a clock-in,
     * compare to shift start (+ grace) for lateness; on a clock-out, compare to
     * shift end for early leave. Falls back to a sensible 09:00–17:00 / 15-min
     * default when no shift is assigned at all.
     */
    protected function determineStatus(string $type, Carbon $now, Employee $employee, ?string $workDate = null): string
    {
        // The shift rostered for the day this punch counts against — not
        // whatever the employee's standing shift happens to be.
        $shift = $employee->shiftOn($workDate ?? $now->toDateString());

        $startTime = $shift->start_time ?? '09:00:00';
        $endTime   = $shift->end_time ?? '17:00:00';
        $grace     = (int) ($shift->late_grace_minutes ?? 15);
        $overnight = $shift?->crossesMidnight() ?? false;
        $small     = $now->hour < Shift::NIGHT_CUTOFF_HOUR;

        if ($type === 'in') {
            $start = Carbon::parse($now->toDateString() . ' ' . $startTime, $now->timezone)
                ->addMinutes($grace);

            // Arriving after midnight for a shift that began last night: the
            // start to measure against is yesterday's, not tonight's.
            if ($overnight && $small) {
                $start->subDay();
            }

            return $now->greaterThan($start) ? 'late' : 'ontime';
        }

        // type === 'out'
        $end = Carbon::parse($now->toDateString() . ' ' . $endTime, $now->timezone);

        // Leaving before midnight on a night shift: the end that matters is
        // tomorrow morning's, so anything now is early.
        if ($overnight && ! $small) {
            $end->addDay();
        }

        return $now->lessThan($end) ? 'early_leave' : 'ontime';
    }

    /**
     * Read a stored scan time as the wall clock it actually is.
     *
     * scanned_at is written in the company's timezone but read back as if it
     * were the app's, so the value is a wall-clock reading with the wrong zone
     * bolted on. Anything that compares it to a real instant, or hands it to a
     * client that will convert it, has to restate it first:
     *
     *   wallClock($log->scanned_at, $company->tz())  → the true instant, for display
     *   wallClock(now($company->tz()))               → "now" in the stored frame, for maths
     *
     * Without this a punch made one second ago in New York looks four hours
     * old. recentlyScanned() sidesteps the same trap by comparing created_at,
     * which is genuinely UTC.
     */
    public function wallClock(Carbon $moment, ?string $timezone = null): Carbon
    {
        return Carbon::parse($moment->format('Y-m-d H:i:s'), $timezone ?? config('app.timezone'));
    }

    /**
     * Minutes worked across a day's punches.
     *
     * Pairs each "in" with the "out" that follows it. Logs must already be in
     * chronological order — this walks them once rather than sorting, because
     * every caller is reading them back out of an ordered query anyway.
     *
     * A stretch still open at the end of the list only counts when $openUntil is
     * given: on today that is "worked so far", but on a past day where somebody
     * forgot to clock out there is no honest number to report, so it counts as
     * nothing rather than as a guess.
     *
     * @param  iterable<int, AttendanceLog>  $logs
     */
    public function workedMinutes(iterable $logs, ?Carbon $openUntil = null): int
    {
        $minutes = 0;
        $openedAt = null;
        $breakStartedAt = null;

        foreach ($logs as $log) {
            // Breaks (A4.15) are deducted from the stretch they sit inside
            // rather than closing it: somebody on their lunch has not clocked
            // out, and treating it as a check-out would make the afternoon look
            // like a second attendance for the day.
            if ($log->type === 'break_start') {
                $breakStartedAt ??= $log->scanned_at;
                continue;
            }

            if ($log->type === 'break_end') {
                if ($breakStartedAt) {
                    $minutes -= max(0, $breakStartedAt->diffInMinutes($log->scanned_at));
                    $breakStartedAt = null;
                }
                continue;
            }

            if ($log->type === 'in') {
                // Two "in"s in a row: the first one is the stretch that is still
                // running, so the later one is ignored rather than restarting it.
                $openedAt ??= $log->scanned_at;
                continue;
            }

            if ($openedAt) {
                $minutes += max(0, $openedAt->diffInMinutes($log->scanned_at));
                $openedAt = null;

                // A break left open when the day was closed out is discarded
                // rather than deducted to the check-out: its length is unknown,
                // and guessing it long would silently cut somebody's hours.
                $breakStartedAt = null;
            }
        }

        if ($openedAt && $openUntil) {
            $minutes += max(0, $openedAt->diffInMinutes($openUntil));

            // Still on a break right now — deduct what has elapsed so "worked
            // today" does not tick upward while somebody is at lunch.
            if ($breakStartedAt) {
                $minutes -= max(0, $breakStartedAt->diffInMinutes($openUntil));
            }
        }

        return (int) max(0, $minutes);
    }

    /** Whether a day's punches include a completed break. */
    public function hasBreakPunches(iterable $logs): bool
    {
        foreach ($logs as $log) {
            if ($log->type === 'break_end') {
                return true;
            }
        }

        return false;
    }

    /**
     * How long the employee was scheduled to work on a date, in minutes.
     *
     * End minus start, less the unpaid break. Null when nobody was rostered —
     * a day off has no schedule to measure against, and returning 0 instead
     * would make "not expected in" indistinguishable from "expected in for no
     * time at all", which is the distinction overtime turns on.
     */
    public function scheduledMinutesFor(Employee $employee, string $workDate): ?int
    {
        $shift = $employee->shiftOn($workDate);

        if (! $shift) {
            return null;
        }

        $start = Carbon::parse($workDate . ' ' . $shift->start_time);
        $end   = Carbon::parse($workDate . ' ' . $shift->end_time);

        // A shift ending before it starts runs past midnight.
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return max(0, (int) $start->diffInMinutes($end) - (int) ($shift->break_minutes ?? 0));
    }

    /**
     * Overtime for one employee on one day (A4.14).
     *
     * Worked time beyond what the day was scheduled for. Four cases, and the
     * awkward ones are the point:
     *
     * - Rostered day, worked past the end: the excess, less a threshold, so
     *   packing up two minutes late does not earn overtime every day.
     * - Unrostered day: every minute is beyond schedule, so all of it counts.
     * - Forgot to check out: workedMinutes reports 0 for a stretch left open on
     *   a past day rather than guessing, so overtime is 0 too. Silence beats an
     *   invented number that ends up in a payroll export.
     * - Implausible day: capped, because one bad punch closed by a later manual
     *   entry should not become a 14-hour claim.
     *
     * @param  iterable<int, AttendanceLog>  $logs  the day's punches, in order
     * @return array{worked: int, scheduled: int|null, overtime: int, rostered: bool, capped: bool}
     *         `worked` is paid time — present time with the unpaid break already
     *         taken out — so that it and `scheduled` are on the same footing and
     *         the difference between them is the overtime.
     */
    public function overtimeFor(Employee $employee, string $workDate, iterable $logs): array
    {
        $logs      = collect($logs);
        $worked    = $this->workedMinutes($logs);
        $scheduled = $this->scheduledMinutesFor($employee, $workDate);
        $rostered  = $scheduled !== null;

        // Compare like with like. `scheduled` already excludes the unpaid
        // break, so `worked` has to as well.
        //
        // Where the day has break punches, workedMinutes has taken the real
        // break out already. Where it has none — every day before A4.15, and
        // every day since where nobody pressed the button — the shift's nominal
        // break is deducted instead.
        //
        // Without this an ordinary 09:00–17:00 day reports 480 worked against
        // 450 scheduled and manufactures 30 minutes of overtime for everybody,
        // every day. It would also mean the staff who diligently punch their
        // breaks earn less overtime than the ones who do not, which is exactly
        // the wrong incentive to build into a payroll figure.
        if ($rostered && ! $this->hasBreakPunches($logs)) {
            $shift = $employee->shiftOn($workDate);
            $worked = max(0, $worked - (int) ($shift->break_minutes ?? 0));
        }

        $threshold = (int) config('attendance.overtime.threshold_minutes', 15);
        $cap       = config('attendance.overtime.daily_cap_minutes');

        if (! $rostered) {
            $overtime = config('attendance.overtime.count_unrostered_days', true) ? $worked : 0;
        } else {
            $over = $worked - $scheduled;
            // The threshold decides whether it counts, not how much: once the
            // day is genuinely long, the whole excess is owed. Subtracting the
            // threshold as well would quietly short every claim by 15 minutes.
            $overtime = $over > $threshold ? $over : 0;
        }

        $capped = false;

        if ($cap !== null && $overtime > (int) $cap) {
            $overtime = (int) $cap;
            $capped = true;
        }

        return [
            'worked'    => $worked,
            'scheduled' => $scheduled,
            'overtime'  => max(0, $overtime),
            'rostered'  => $rostered,
            'capped'    => $capped,
        ];
    }

    /**
     * The moment an employee's shift ended on a given work date.
     *
     * On a shift that crosses midnight this is the following morning, which is
     * the whole reason it is computed here rather than pasted together from a
     * date and a time at each call site. Null when nobody is rostered — a day
     * off has no end to measure against.
     */
    public function shiftEndFor(Employee $employee, string $workDate): ?Carbon
    {
        $shift = $employee->shiftOn($workDate);

        if (! $shift) {
            return null;
        }

        $end = Carbon::parse($workDate . ' ' . $shift->end_time);

        return $shift->crossesMidnight() ? $end->addDay() : $end;
    }

    /**
     * Days where somebody clocked in and never clocked out.
     *
     * Keyed by employee so a caller can act per person. Only the most recent
     * punch matters: an in/out/in sequence is still open, and an in/out one is
     * not, regardless of how many pairs came before.
     *
     * @return \Illuminate\Support\Collection<int, AttendanceLog>
     */
    public function openPunches(int $companyId, string $workDate): \Illuminate\Support\Collection
    {
        return AttendanceLog::with('employee')
            ->where('company_id', $companyId)
            ->whereDate('work_date', $workDate)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($logs) => $logs->last())
            ->filter(fn (AttendanceLog $last) => $last->type === 'in');
    }

    /**
     * Who is on the clock at this moment (A4.19).
     *
     * Three buckets, because "who is in" has three honest answers and a board
     * that shows only the first is the one people stop trusting:
     *
     *  - `in`      — clocked in, not yet out. Includes people on a break, with
     *                the break flagged, because they are still at work.
     *  - `left`    — clocked in and back out again. They were here today.
     *  - `not_in`  — no punch at all, and no approved leave. This is the bucket
     *                somebody is actually looking for.
     *
     * Approved leave is reported separately and never as "not in": the board is
     * read to find out who is unaccounted for, and somebody on booked holiday is
     * accounted for.
     *
     * Yesterday's date is considered as well as today's, because a night shift
     * that began yesterday is still yesterday's work date at two in the morning
     * and the person is very much at work.
     *
     * @return array{in: array, left: array, not_in: array, on_leave: array, as_of: \Carbon\Carbon}
     */
    public function whoIsIn(int $companyId, ?int $officeId = null): array
    {
        $company = \App\Models\Company::find($companyId);
        $now = Carbon::now($company?->tz() ?? config('app.timezone'));

        $employees = Employee::with(['department', 'office'])
            ->where('company_id', $companyId)->active()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('first_name')
            ->get();

        // forDates rather than whereIn on the two dates: work_date carries a
        // time component on any engine without a real DATE type, so a string
        // match against "2026-08-05" finds nothing.
        $punches = AttendanceLog::whereIn('employee_id', $employees->pluck('id'))
            ->forDates($now->copy()->subDay()->toDateString(), $now->toDateString())
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $onLeave = $this->leave->onLeaveOn($companyId, $now->toDateString());

        $buckets = ['in' => [], 'left' => [], 'not_in' => [], 'on_leave' => []];

        foreach ($employees as $employee) {
            $logs = $punches->get($employee->id, collect());
            $last = $logs->last();

            if ($last && in_array($last->type, ['in', 'break_start', 'break_end'], true)) {
                $buckets['in'][] = [
                    'employee'  => $employee,
                    'since'     => $logs->firstWhere('type', 'in')?->scanned_at,
                    'on_break'  => $last->type === 'break_start',
                    'last'      => $last,
                ];

                continue;
            }

            if ($last && $last->type === 'out') {
                $buckets['left'][] = [
                    'employee' => $employee,
                    'since'    => $logs->firstWhere('type', 'in')?->scanned_at,
                    'until'    => $last->scanned_at,
                ];

                continue;
            }

            // No punch at all. Booked leave explains it; nothing else does.
            if ($onLeave->has($employee->id)) {
                $buckets['on_leave'][] = [
                    'employee' => $employee,
                    'leave'    => $onLeave->get($employee->id),
                ];

                continue;
            }

            $buckets['not_in'][] = ['employee' => $employee];
        }

        $buckets['as_of'] = $now;

        return $buckets;
    }

    /**
     * Aggregate summary tiles for a date (company-wide or per office).
     *
     * Approved leave is not absence. Somebody who booked the day off and did not
     * clock in is reported as on leave; only the remainder — nobody knows where
     * they are — counts as absent.
     *
     * @return array{present:int,late:int,on_leave:int,absent:int,total:int}
     */
    public function daySummary(int $companyId, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $totalEmployees = Employee::where('company_id', $companyId)->active()->count();

        $rows = AttendanceLog::query()
            ->select('employee_id', DB::raw("MIN(CASE WHEN type='in' THEN status END) as in_status"))
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('work_date', $date)
            ->where('type', 'in')
            ->groupBy('employee_id')
            ->get();

        $present = $rows->count();
        $late = $rows->where('in_status', 'late')->count();

        // Anyone who turned up despite booking the day off is counted present,
        // not on leave — otherwise the three tiles would sum past the headcount.
        $onLeave = $this->leave->onLeaveOn($companyId, $date)
            ->keys()
            ->diff($rows->pluck('employee_id'))
            ->count();

        $absent = max(0, $totalEmployees - $present - $onLeave);

        return [
            'present'  => $present,
            'late'     => $late,
            'on_leave' => $onLeave,
            'absent'   => $absent,
            'total'    => $totalEmployees,
        ];
    }

    /**
     * Close a day somebody clocked into and never out of.
     *
     * Writes a real "out" punch at the shift's scheduled end, marked
     * `source: auto` with a note saying so. Two alternatives were worse:
     * leaving it open reports zero hours for a day that was worked, and
     * stamping the current time would credit every hour since — somebody who
     * forgot on Friday would show as having worked the weekend.
     *
     * The row is deliberately indistinguishable from a real punch to every
     * report, and completely distinguishable to anyone looking at it: the
     * hours are the scheduled ones, and the source says nobody pressed a
     * button. HR corrects it from there.
     */
    public function autoClose(AttendanceLog $openPunch): ?AttendanceLog
    {
        $employee = $openPunch->employee;
        $workDate = $openPunch->work_date->toDateString();

        $end = $employee ? $this->shiftEndFor($employee, $workDate) : null;

        if (! $end) {
            return null;
        }

        // Someone who clocked in after their shift had already ended would
        // otherwise get an "out" before their "in".
        if ($end->lessThanOrEqualTo($openPunch->scanned_at)) {
            return null;
        }

        return AttendanceLog::create([
            'company_id'  => $openPunch->company_id,
            'employee_id' => $openPunch->employee_id,
            'office_id'   => $openPunch->office_id,
            'type'        => 'out',
            'scanned_at'  => $end,
            'work_date'   => $workDate,
            'status'      => 'ontime',
            'source'      => 'auto',
            'notes'       => 'Automatically closed at the scheduled shift end — no clock-out was recorded.',
        ]);
    }

    /**
     * Recompute and store a monthly attendance score for an employee.
     *
     * Absence is measured against the days the company actually works, and an
     * approved leave day is accounted for rather than counted against the
     * employee. Only a working day with no punch and no leave is an absence.
     */
    public function computeMonthlyScore(Employee $employee, string $period): void
    {
        [$year, $month] = explode('-', $period);

        $start = Carbon::create((int) $year, (int) $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $month)
            ->where('type', 'in')
            ->get();

        $presentDates = $logs->pluck('work_date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique();

        $presentDays = $presentDates->count();
        $lateCount = $logs->where('status', 'late')->count();
        $ontime = max(0, $presentDays - $lateCount);
        $ontimePct = $presentDays > 0 ? round($ontime / $presentDays * 100, 2) : 0;

        $expected = count($this->leave->workingDatesBetween(
            $employee->company, $start->toDateString(), $end->toDateString(),
        ));

        $leaveDates = collect($this->leave->leaveDatesByEmployee(
            $employee->company_id, $start->toDateString(), $end->toDateString(),
        )[$employee->id] ?? []);

        // Union, not a subtraction of both counts: somebody who clocked in on a
        // day they had also booked off would otherwise be subtracted twice and
        // pull the absence count below what it should be.
        $covered = $presentDates->merge($leaveDates)->unique()->count();

        \App\Models\AttendanceScore::updateOrCreate(
            ['employee_id' => $employee->id, 'period' => $period, 'period_type' => 'monthly'],
            [
                'company_id'   => $employee->company_id,
                'present_days' => $presentDays,
                'late_count'   => $lateCount,
                'leave_days'   => $leaveDates->count(),
                'absent_count' => max(0, $expected - $covered),
                'ontime_pct'   => $ontimePct,
                'score'        => $ontimePct,
            ]
        );
    }
}
