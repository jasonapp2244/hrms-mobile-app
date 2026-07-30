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
        // Server-authoritative time — never trust the device clock
        $now = Carbon::now($office->company?->tz() ?? config('app.timezone'));
        $workDate = $this->workDateFor($employee, $now);

        // Determine whether this scan is a clock-in or clock-out:
        // if the last event today was an "in", this one is an "out", else "in".
        // whereDate, not a plain equality: work_date is cast to a date, so the
        // value written carries a 00:00:00 time on any engine without a real
        // DATE type. MySQL truncates it, SQLite does not — matching on the date
        // itself works on both.
        $lastToday = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->orderByDesc('scanned_at')
            ->first();

        $type = ($lastToday && $lastToday->type === 'in') ? 'out' : 'in';

        $status = $this->determineStatus($type, $now, $employee);

        $log = AttendanceLog::create([
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
     */
    protected function workDateFor(Employee $employee, Carbon $now): string
    {
        $shift = $employee->shift;

        if ($shift && $shift->crossesMidnight() && $now->hour < Shift::NIGHT_CUTOFF_HOUR) {
            return $now->copy()->subDay()->toDateString();
        }

        return $now->toDateString();
    }

    /**
     * Status is measured against the shift the employee actually works — their
     * own if one is set for them, otherwise their department's. On a clock-in,
     * compare to shift start (+ grace) for lateness; on a clock-out, compare to
     * shift end for early leave. Falls back to a sensible 09:00–17:00 / 15-min
     * default when no shift is assigned at all.
     */
    protected function determineStatus(string $type, Carbon $now, Employee $employee): string
    {
        $shift = $employee->shift;

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
