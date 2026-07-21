<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * Record a clock in/out event after the QR token has been validated.
     * Determines type (in/out) automatically and computes late/early status.
     *
     * @return array{log: AttendanceLog, type: string, status: string}
     */
    public function record(Employee $employee, Office $office, array $meta = []): array
    {
        // Server-authoritative time — never trust the device clock
        $now = Carbon::now($office->company?->tz() ?? config('app.timezone'));
        $workDate = $now->toDateString();

        // Determine whether this scan is a clock-in or clock-out:
        // if the last event today was an "in", this one is an "out", else "in".
        $lastToday = AttendanceLog::where('employee_id', $employee->id)
            ->where('work_date', $workDate)
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
     * Status is measured against the employee's shift (inherited from their
     * department). On a clock-in, compare to shift start (+ grace) for lateness;
     * on a clock-out, compare to shift end for early leave. Falls back to a
     * sensible 09:00–17:00 / 15-min default when no shift is assigned.
     */
    protected function determineStatus(string $type, Carbon $now, Employee $employee): string
    {
        $shift = $employee->shift; // inherited from department

        $startTime = $shift->start_time ?? '09:00:00';
        $endTime   = $shift->end_time ?? '17:00:00';
        $grace     = (int) ($shift->late_grace_minutes ?? 15);

        if ($type === 'in') {
            $start = Carbon::parse($now->toDateString() . ' ' . $startTime, $now->timezone)
                ->addMinutes($grace);
            return $now->greaterThan($start) ? 'late' : 'ontime';
        }

        // type === 'out'
        $end = Carbon::parse($now->toDateString() . ' ' . $endTime, $now->timezone);
        return $now->lessThan($end) ? 'early_leave' : 'ontime';
    }

    /**
     * Aggregate summary tiles for a date (company-wide or per office).
     *
     * @return array{present:int,late:int,absent:int,total:int}
     */
    public function daySummary(int $companyId, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $totalEmployees = Employee::where('company_id', $companyId)->active()->count();

        $rows = AttendanceLog::query()
            ->select('employee_id', DB::raw("MIN(CASE WHEN type='in' THEN status END) as in_status"))
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->where('work_date', $date)
            ->where('type', 'in')
            ->groupBy('employee_id')
            ->get();

        $present = $rows->count();
        $late = $rows->where('in_status', 'late')->count();
        $absent = max(0, $totalEmployees - $present);

        return [
            'present' => $present,
            'late'    => $late,
            'absent'  => $absent,
            'total'   => $totalEmployees,
        ];
    }

    /**
     * Recompute and store a monthly attendance score for an employee.
     */
    public function computeMonthlyScore(Employee $employee, string $period): void
    {
        [$year, $month] = explode('-', $period);
        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $month)
            ->where('type', 'in')
            ->get();

        $presentDays = $logs->pluck('work_date')->unique()->count();
        $lateCount = $logs->where('status', 'late')->count();
        $ontime = max(0, $presentDays - $lateCount);
        $ontimePct = $presentDays > 0 ? round($ontime / $presentDays * 100, 2) : 0;

        $employee->company; // ensure relation available
        \App\Models\AttendanceScore::updateOrCreate(
            ['employee_id' => $employee->id, 'period' => $period, 'period_type' => 'monthly'],
            [
                'present_days' => $presentDays,
                'late_count'   => $lateCount,
                'absent_count' => 0,
                'ontime_pct'   => $ontimePct,
                'score'        => $ontimePct,
            ]
        );
    }
}
