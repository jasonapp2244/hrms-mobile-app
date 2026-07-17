<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Computes the HR analytics reports (late arrivals, outliers, department rollups).
 * Each builder returns a uniform structure so the views + PDF/Excel exports are generic:
 *   ['title','subtitle','tiles'=>[['label','value']],'headings'=>[...],'rows'=>[assoc array]]
 */
class ReportService
{
    /**
     * Per-employee attendance stats for the period, keyed by employee id.
     * @return Collection<int,array>
     */
    protected function employeeStats(int $companyId, string $from, string $to, ?int $officeId = null): Collection
    {
        $employees = Employee::with(['department', 'office'])
            ->where('company_id', $companyId)->active()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->get();

        $insByEmp = AttendanceLog::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$from, $to])
            ->where('type', 'in')
            ->get()
            ->groupBy('employee_id');

        return $employees->map(function (Employee $e) use ($insByEmp) {
            $ins = $insByEmp->get($e->id, collect());
            $presentDays = $ins->pluck('work_date')->map->toDateString()->unique()->count();
            $late = $ins->where('status', 'late')->count();
            $ontime = $ins->where('status', 'ontime')->count();
            $totalIns = $ins->count();
            $ontimePct = $totalIns > 0 ? round($ontime / $totalIns * 100, 1) : null;

            return [
                'employee'     => $e,
                'present_days' => $presentDays,
                'late'         => $late,
                'ontime'       => $ontime,
                'total_ins'    => $totalIns,
                'ontime_pct'   => $ontimePct,
            ];
        })->keyBy(fn ($s) => $s['employee']->id);
    }

    /** Late Arrivals report — employees ranked by number of late clock-ins. */
    public function late(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        $stats = $this->employeeStats($companyId, $from, $to, $officeId)
            ->filter(fn ($s) => $s['late'] > 0)
            ->sortByDesc('late');

        $rows = $stats->map(function ($s) {
            $latePct = $s['total_ins'] > 0 ? round($s['late'] / $s['total_ins'] * 100, 1) : 0;
            return [
                'Employee'     => $s['employee']->full_name,
                'Code'         => $s['employee']->employee_code,
                'Department'   => $s['employee']->department->name ?? '—',
                'Office'       => $s['employee']->office->name ?? '—',
                'Present Days' => $s['present_days'],
                'Late Count'   => $s['late'],
                'Late %'       => $latePct . '%',
            ];
        })->values()->all();

        return [
            'title'    => 'Late Arrivals Report',
            'subtitle' => 'Employees with late clock-ins in the selected period',
            'tiles'    => [
                ['label' => 'Employees Late', 'value' => count($rows)],
                ['label' => 'Total Late Scans', 'value' => $stats->sum('late')],
            ],
            'headings' => ['Employee', 'Code', 'Department', 'Office', 'Present Days', 'Late Count', 'Late %'],
            'rows'     => $rows,
        ];
    }

    /** Attendance Outlier report — flags statistically abnormal attendance. */
    public function outliers(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        $stats = $this->employeeStats($companyId, $from, $to, $officeId)
            ->filter(fn ($s) => $s['total_ins'] > 0); // only employees with activity

        $ontimePcts = $stats->pluck('ontime_pct')->filter(fn ($v) => $v !== null)->values();
        $lateCounts = $stats->pluck('late')->values();

        $meanOntime = $ontimePcts->avg() ?? 0;
        $sdOntime = $this->stddev($ontimePcts);
        $meanLate = $lateCounts->avg() ?? 0;
        $sdLate = $this->stddev($lateCounts);

        $ontimeFloor = max(60, $meanOntime - $sdOntime);   // below this on-time % is an outlier
        $lateCeiling = $meanLate + max(1, $sdLate);         // above this late count is an outlier

        $rows = $stats->map(function ($s) use ($ontimeFloor, $lateCeiling) {
            $flags = [];
            if ($s['ontime_pct'] !== null && $s['ontime_pct'] < $ontimeFloor) {
                $flags[] = 'Low on-time %';
            }
            if ($s['late'] > $lateCeiling) {
                $flags[] = 'High lateness';
            }
            return [
                'stat'  => $s,
                'flags' => $flags,
            ];
        })->filter(fn ($r) => count($r['flags']) > 0)
          ->sortBy(fn ($r) => $r['stat']['ontime_pct'] ?? 0);

        $tableRows = $rows->map(function ($r) {
            $s = $r['stat'];
            return [
                'Employee'   => $s['employee']->full_name,
                'Department' => $s['employee']->department->name ?? '—',
                'Present Days' => $s['present_days'],
                'Late Count' => $s['late'],
                'On-time %'  => ($s['ontime_pct'] ?? 0) . '%',
                'Flag'       => implode(', ', $r['flags']),
            ];
        })->values()->all();

        return [
            'title'    => 'Attendance Outlier Report',
            'subtitle' => sprintf('Threshold: on-time %% below %s or late count above %s', round($ontimeFloor, 1), round($lateCeiling, 1)),
            'tiles'    => [
                ['label' => 'Employees Analyzed', 'value' => $stats->count()],
                ['label' => 'Outliers Flagged', 'value' => count($tableRows)],
                ['label' => 'Avg On-time %', 'value' => round($meanOntime, 1) . '%'],
            ],
            'headings' => ['Employee', 'Department', 'Present Days', 'Late Count', 'On-time %', 'Flag'],
            'rows'     => $tableRows,
        ];
    }

    /** Department report — attendance rolled up per department. */
    public function department(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        $stats = $this->employeeStats($companyId, $from, $to, $officeId);

        $byDept = $stats->groupBy(fn ($s) => $s['employee']->department->name ?? 'Unassigned');

        $rows = $byDept->map(function ($group, $deptName) {
            $employees = $group->count();
            $late = $group->sum('late');
            $presentDays = $group->sum('present_days');
            $ontime = $group->sum('ontime');
            $totalIns = $group->sum('total_ins');
            $ontimePct = $totalIns > 0 ? round($ontime / $totalIns * 100, 1) : 0;
            return [
                'Department'   => $deptName,
                'Employees'    => $employees,
                'Present (emp-days)' => $presentDays,
                'Late Count'   => $late,
                'On-time %'    => $ontimePct . '%',
            ];
        })->sortByDesc('Employees')->values()->all();

        return [
            'title'    => 'Department Attendance Report',
            'subtitle' => 'Attendance rolled up by department',
            'tiles'    => [
                ['label' => 'Departments', 'value' => count($rows)],
                ['label' => 'Employees', 'value' => $stats->count()],
                ['label' => 'Total Present Days', 'value' => $stats->sum('present_days')],
            ],
            'headings' => ['Department', 'Employees', 'Present (emp-days)', 'Late Count', 'On-time %'],
            'rows'     => $rows,
        ];
    }

    protected function stddev(Collection $values): float
    {
        $n = $values->count();
        if ($n < 2) return 0.0;
        $mean = $values->avg();
        $variance = $values->reduce(fn ($c, $v) => $c + pow($v - $mean, 2), 0) / $n;
        return sqrt($variance);
    }
}
