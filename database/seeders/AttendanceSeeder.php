<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    /**
     * Generate realistic attendance over the last N days so the dashboard
     * trend chart, logs, and reports show meaningful data.
     */
    public function run(): void
    {
        $days = 14;                 // how far back to generate
        $service = app(AttendanceService::class);
        $employees = Employee::with('office')->active()->get();

        foreach (range($days, 1) as $ago) {
            $date = Carbon::today()->subDays($ago);

            // Skip weekends for realism
            if ($date->isWeekend()) {
                continue;
            }

            foreach ($employees as $employee) {
                $office = $employee->office;
                if (! $office) {
                    continue;
                }

                // ~12% chance the employee is absent that day
                if (mt_rand(1, 100) <= 12) {
                    continue;
                }

                // Clock IN: normally near work_start, sometimes late
                $start = Carbon::parse($date->toDateString() . ' ' . $office->work_start_time);
                $inOffset = mt_rand(-20, 55); // minutes around start
                $in = (clone $start)->addMinutes($inOffset);

                $lateThreshold = (clone $start)->addMinutes((int) $office->late_grace_minutes);
                $inStatus = $in->greaterThan($lateThreshold) ? 'late' : 'ontime';

                // Clock OUT: near work_end, sometimes early
                $end = Carbon::parse($date->toDateString() . ' ' . $office->work_end_time);
                $outOffset = mt_rand(-25, 40);
                $out = (clone $end)->addMinutes($outOffset);
                $outStatus = $out->lessThan($end) ? 'early_leave' : 'ontime';

                AttendanceLog::create([
                    'employee_id' => $employee->id,
                    'office_id'   => $office->id,
                    'type'        => 'in',
                    'scanned_at'  => $in,
                    'work_date'   => $date->toDateString(),
                    'status'      => $inStatus,
                    'source'      => 'kiosk',
                ]);

                AttendanceLog::create([
                    'employee_id' => $employee->id,
                    'office_id'   => $office->id,
                    'type'        => 'out',
                    'scanned_at'  => $out,
                    'work_date'   => $date->toDateString(),
                    'status'      => $outStatus,
                    'source'      => 'kiosk',
                ]);
            }
        }

        // Recompute this month's scores for everyone
        $period = Carbon::today()->format('Y-m');
        foreach ($employees as $employee) {
            $service->computeMonthlyScore($employee, $period);
        }
    }
}
