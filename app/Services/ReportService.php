<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Computes the HR analytics reports (late arrivals, outliers, department rollups).
 * Each builder returns a uniform structure so the views + PDF/Excel exports are generic:
 *   ['title','subtitle','tiles'=>[['label','value']],'headings'=>[...],'rows'=>[assoc array]]
 */
class ReportService
{
    public function __construct(
        protected LeaveService $leave,
        protected AttendanceService $attendance,
    ) {}

    /**
     * Overtime report (A4.14) — worked time beyond schedule, per employee.
     *
     * Does not go through employeeStats(): that loads only "in" punches, which
     * is all the lateness reports need, whereas overtime has to pair each in
     * with its out to know how long anybody was actually here.
     */
    public function overtime(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        // `shift` is an accessor over shiftOverride ?? department.shift, so both
        // sides of it are loaded here along with the roster — otherwise every
        // employee-day below fires its own query to work out the schedule.
        $employees = Employee::with(['department.shift', 'office', 'shiftOverride', 'shiftAssignments.shift'])
            ->where('company_id', $companyId)->active()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->get();

        // Ordered here so workedMinutes can walk each day once — it pairs in
        // sequence rather than sorting, and unordered punches would pair a
        // morning check-in with the previous evening's check-out.
        $punches = AttendanceLog::whereIn('employee_id', $employees->pluck('id'))
            ->forDates($from, $to)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $capped = 0;

        $stats = $employees->map(function (Employee $e) use ($punches, &$capped) {
            $byDate = $punches->get($e->id, collect())
                ->groupBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

            $minutes = 0;
            $days = 0;
            $unrostered = 0;

            foreach ($byDate as $date => $dayLogs) {
                $result = $this->attendance->overtimeFor($e, $date, $dayLogs);

                if ($result['overtime'] <= 0) {
                    continue;
                }

                $minutes += $result['overtime'];
                $days++;

                if (! $result['rostered']) {
                    $unrostered++;
                }

                if ($result['capped']) {
                    $capped++;
                }
            }

            return [
                'employee'   => $e,
                'minutes'    => $minutes,
                'days'       => $days,
                'unrostered' => $unrostered,
            ];
        })->filter(fn ($s) => $s['minutes'] > 0)->sortByDesc('minutes');

        $rows = $stats->map(fn ($s) => [
            'Employee'      => $s['employee']->full_name,
            'Code'          => $s['employee']->employee_code,
            'Department'    => $s['employee']->department->name ?? '—',
            'Office'        => $s['employee']->office->name ?? '—',
            'Days'          => $s['days'],
            'Unrostered'    => $s['unrostered'],
            'Overtime'      => $this->asHours($s['minutes']),
            'Avg / Day'     => $s['days'] > 0 ? $this->asHours((int) round($s['minutes'] / $s['days'])) : '—',
        ])->values()->all();

        $total = $stats->sum('minutes');
        $threshold = (int) config('attendance.overtime.threshold_minutes', 15);

        return [
            'title'    => 'Overtime Report',
            'subtitle' => $capped > 0
                // Surfaced rather than swallowed: a capped day is usually a
                // forgotten check-out, which is a data problem to fix and not
                // an entitlement to pay.
                ? sprintf('Counted past %d minutes over schedule · %d day(s) hit the daily cap and are worth checking', $threshold, $capped)
                : sprintf('Counted past %d minutes over schedule', $threshold),
            'tiles'    => [
                ['label' => 'Employees With Overtime', 'value' => count($rows)],
                ['label' => 'Total Overtime', 'value' => $this->asHours($total)],
                ['label' => 'Overtime Days', 'value' => $stats->sum('days')],
            ],
            'headings' => ['Employee', 'Code', 'Department', 'Office', 'Days', 'Unrostered', 'Overtime', 'Avg / Day'],
            'rows'     => $rows,
        ];
    }

    /** Minutes as "7h 45m" — hours are how overtime is discussed and paid. */
    protected function asHours(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    /**
     * Leave report (A7.10) — days taken per employee, broken down by type.
     *
     * The type columns are built from the company's own leave types rather than
     * hardcoded, so a company that renames "Casual" to "Personal" gets the
     * column it expects. Everything downstream — the table, the PDF, the Excel
     * sheet — already reads its columns out of `headings`, so nothing else has
     * to know the shape varies.
     *
     * Days are counted by intersecting each request with the reporting window,
     * not by reading `days` off the request. A week booked across a month
     * boundary belongs half to each month, and a manager comparing March
     * against April would otherwise see the whole week land in whichever month
     * the request happened to start.
     *
     * Every active employee appears, including the ones who took nothing. "Who
     * has not taken a day all year" is a question this report gets asked as
     * often as "who has taken the most", and it cannot be answered by a table
     * that omits them.
     */
    public function leave(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        $employees = Employee::with(['department', 'office'])
            ->where('company_id', $companyId)->active()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->get();

        $types = LeaveType::where('company_id', $companyId)->active()->orderBy('name')->get();

        $taken   = $this->leaveDaysByType($companyId, $from, $to, 'approved');
        $pending = $this->leaveDaysByType($companyId, $from, $to, 'pending');

        // Balances are a year's ledger, not a range's — so they are read for the
        // year the window ends in, and the subtitle says so. Reporting on a
        // range that straddles New Year gives the later year's remainder, which
        // is the one anybody planning cover actually needs.
        $year = (int) Carbon::parse($to)->year;
        $remaining = $this->remainingByEmployee($companyId, $year, $types->pluck('id')->all());

        // A leave type called "Employee" or "Total Days" would otherwise
        // overwrite a fixed column, since rows are keyed by heading text.
        $fixed = ['Code', 'Employee', 'Department', 'Office', 'Total Days', 'Pending', 'Remaining'];
        $columns = [];

        foreach ($types as $type) {
            $label = in_array($type->name, $fixed, true) ? $type->name . ' (leave)' : $type->name;
            $columns[$type->id] = $label;
        }

        $rows = $employees->map(function (Employee $e) use ($columns, $taken, $pending, $remaining) {
            $mine = $taken[$e->id] ?? [];

            $row = [
                'Code'       => $e->employee_code,
                'Employee'   => $e->full_name,
                'Department' => $e->department->name ?? '—',
                'Office'     => $e->office->name ?? '—',
            ];

            foreach ($columns as $typeId => $label) {
                $row[$label] = $this->days($mine[$typeId] ?? 0.0);
            }

            $row['Total Days'] = $this->days(array_sum($mine));
            $row['Pending']    = $this->days(array_sum($pending[$e->id] ?? []));
            $row['Remaining']  = $this->days($remaining[$e->id] ?? 0.0);

            return $row;
        })
            ->sortByDesc('Total Days')
            ->values()->all();

        $totalTaken = array_sum(array_map('array_sum', $taken));
        $onLeave    = count(array_filter($taken, fn ($byType) => array_sum($byType) > 0));

        // Named rather than left to the reader to spot down a column of numbers:
        // which type dominates is the finding that changes a policy.
        $byType = [];
        foreach ($taken as $byTypeForEmployee) {
            foreach ($byTypeForEmployee as $typeId => $days) {
                $byType[$typeId] = ($byType[$typeId] ?? 0) + $days;
            }
        }
        arsort($byType);
        $topType = $byType ? ($columns[array_key_first($byType)] ?? '—') : '—';

        return [
            'title'    => 'Leave Report',
            'subtitle' => sprintf(
                'Approved leave days falling inside the period, split by type. '
                . 'Weekends and company holidays are excluded; a half day counts as 0.5. '
                . 'Remaining is the %d entitlement still unspent.',
                $year,
            ),
            'tiles' => [
                ['label' => 'Employees On Leave', 'value' => $onLeave],
                ['label' => 'Days Taken', 'value' => $this->days($totalTaken)],
                ['label' => 'Pending Days', 'value' => $this->days(array_sum(array_map('array_sum', $pending)))],
                ['label' => 'Most Used', 'value' => $topType],
            ],
            'headings' => array_merge(
                ['Code', 'Employee', 'Department', 'Office'],
                array_values($columns),
                ['Total Days', 'Pending', 'Remaining'],
            ),
            'rows' => $rows,
        ];
    }

    /**
     * Leave days inside the window, as employee_id => [leave_type_id => days].
     *
     * Shares its rule with LeaveService::leaveDatesByEmployee — only working
     * dates count — so the leave report and the attendance reports cannot
     * disagree about how long somebody was away. It splits by type and carries
     * the half-day, neither of which the attendance side needs.
     *
     * @return array<int, array<int, float>>
     */
    protected function leaveDaysByType(int $companyId, string $from, string $to, string $status): array
    {
        $company = Company::find($companyId);
        $working = array_flip($this->leave->workingDatesBetween($company, $from, $to));

        $requests = LeaveRequest::where('company_id', $companyId)
            ->where('status', $status)
            ->overlapping($from, $to)
            ->get();

        $out = [];

        foreach ($requests as $request) {
            $span = Carbon::parse(max($request->start_date->toDateString(), $from));
            $last = Carbon::parse(min($request->end_date->toDateString(), $to));

            $days = 0.0;

            for ($day = $span->copy(); $day->lte($last); $day->addDay()) {
                if (isset($working[$day->toDateString()])) {
                    $days += $request->is_half_day ? 0.5 : 1.0;
                }
            }

            if ($days > 0) {
                $out[$request->employee_id][$request->leave_type_id]
                    = ($out[$request->employee_id][$request->leave_type_id] ?? 0.0) + $days;
            }
        }

        return $out;
    }

    /**
     * Unspent entitlement for the year, summed across types, per employee.
     *
     * Only counts types still active: a retired leave type's leftover days are
     * not bookable, so showing them as remaining would overstate what anybody
     * can actually take.
     *
     * @param  array<int, int>  $typeIds
     * @return array<int, float>
     */
    protected function remainingByEmployee(int $companyId, int $year, array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return LeaveBalance::where('company_id', $companyId)
            ->where('year', $year)
            ->whereIn('leave_type_id', $typeIds)
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($balances) => (float) $balances->sum(fn (LeaveBalance $b) => $b->available))
            ->all();
    }

    /**
     * Days for display: whole where whole, one place where a half day is in play.
     *
     * Returned as a float rather than a string so the Excel export totals — the
     * same reason payroll hours are numeric. "3" beats "3.0" on screen, and a
     * float carries both.
     */
    protected function days(float $days): float
    {
        return round($days, 1);
    }

    /**
     * Payroll export (A7.14) — hours per employee for the period.
     *
     * Built to be pasted into a payroll run, which drives two decisions that
     * differ from the screen-facing reports.
     *
     * Hours are decimal, not "7h 30m". Payroll multiplies by a rate; nobody
     * wants to parse that string, and 7.5 is unambiguous where 7.30 is not.
     *
     * Every active employee appears, including those with nothing to show.
     * Filtering out the zero rows is right for an outlier report and wrong
     * here: a missing employee reads as an oversight, and the person checking
     * the run needs to see that somebody was absent all month rather than
     * wonder whether the export dropped them.
     */
    public function payroll(int $companyId, string $from, string $to, ?int $officeId = null): array
    {
        $employees = Employee::with(['department.shift', 'office', 'shiftOverride', 'shiftAssignments.shift'])
            ->where('company_id', $companyId)->active()
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('employee_code')
            ->get();

        $punches = AttendanceLog::whereIn('employee_id', $employees->pluck('id'))
            ->forDates($from, $to)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $workingDays = count($this->leave->workingDatesBetween(Company::find($companyId), $from, $to));
        $leaveDates  = $this->leave->leaveDatesByEmployee($companyId, $from, $to);

        $rows = $employees->map(function (Employee $e) use ($punches, $workingDays, $leaveDates) {
            $byDate = $punches->get($e->id, collect())
                ->groupBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

            $paid = 0;
            $overtime = 0;
            $daysWorked = 0;

            foreach ($byDate as $date => $dayLogs) {
                $result = $this->attendance->overtimeFor($e, $date, $dayLogs);

                $paid += $result['worked'];
                $overtime += $result['overtime'];

                // A day only counts as worked if something was actually paid for
                // it. A lone check-in with no check-out yields nothing, and
                // counting it would inflate the day count against zero hours.
                if ($result['worked'] > 0) {
                    $daysWorked++;
                }
            }

            $leaveDays = count($leaveDates[$e->id] ?? []);

            return [
                'Code'            => $e->employee_code,
                'Employee'        => $e->full_name,
                'Department'      => $e->department->name ?? '—',
                'Days Worked'     => $daysWorked,
                'Leave Days'      => $leaveDays,
                'Absent Days'     => max(0, $workingDays - $daysWorked - $leaveDays),
                'Regular Hours'   => $this->decimalHours($paid - $overtime),
                'Overtime Hours'  => $this->decimalHours($overtime),
                'Total Hours'     => $this->decimalHours($paid),
            ];
        })->values()->all();

        $totalPaid = array_sum(array_column($rows, 'Total Hours'));
        $totalOt   = array_sum(array_column($rows, 'Overtime Hours'));

        return [
            'title'    => 'Payroll Hours',
            'subtitle' => sprintf(
                'Decimal hours per employee · %d working day(s) in the period · breaks excluded',
                $workingDays,
            ),
            'tiles'    => [
                ['label' => 'Employees', 'value' => count($rows)],
                ['label' => 'Total Hours', 'value' => number_format($totalPaid, 2)],
                ['label' => 'Of Which Overtime', 'value' => number_format($totalOt, 2)],
            ],
            'headings' => [
                'Code', 'Employee', 'Department', 'Days Worked', 'Leave Days',
                'Absent Days', 'Regular Hours', 'Overtime Hours', 'Total Hours',
            ],
            'rows'     => $rows,
        ];
    }

    /**
     * Minutes as decimal hours, to two places.
     *
     * Returned as a float rather than a formatted string so the Excel export
     * carries a number the spreadsheet can total. A right-aligned "7.50" that
     * cannot be summed is the single most annoying thing to receive in a
     * payroll file.
     */
    protected function decimalHours(int $minutes): float
    {
        return round(max(0, $minutes) / 60, 2);
    }

    /**
     * The fields a custom report can be built from (A7.13).
     *
     * Declared as data because three places need the same list and must not
     * drift: the column picker on screen, the validator that decides a
     * requested column is real, and the builder that fills it in.
     *
     * Grouped for the picker — a flat list of eighteen checkboxes is a wall,
     * and the grouping is the difference between choosing columns and hunting
     * for them.
     */
    public const CUSTOM_COLUMNS = [
        'Who' => [
            'code'        => 'Code',
            'name'        => 'Employee',
            'department'  => 'Department',
            'office'      => 'Office',
            'designation' => 'Designation',
            'manager'     => 'Manager',
            'work_mode'   => 'Work Mode',
            'hire_date'   => 'Hire Date',
        ],
        'Attendance' => [
            'present_days' => 'Present Days',
            'leave_days'   => 'Leave Days',
            'absent_days'  => 'Absent Days',
            'late'         => 'Late Count',
            'ontime'       => 'On-time Count',
            'ontime_pct'   => 'On-time %',
        ],
        'Hours' => [
            'days_worked'    => 'Days Worked',
            'regular_hours'  => 'Regular Hours',
            'overtime_hours' => 'Overtime Hours',
            'total_hours'    => 'Total Hours',
        ],
    ];

    /** The default selection — a readable report for somebody who picks nothing. */
    public const CUSTOM_DEFAULT = ['code', 'name', 'department', 'present_days', 'late', 'ontime_pct'];

    /** Every column key, flat. */
    public static function customColumnKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CUSTOM_COLUMNS)));
    }

    /** A column key's heading. */
    public static function customColumnLabel(string $key): ?string
    {
        foreach (self::CUSTOM_COLUMNS as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }

        return null;
    }

    /**
     * Custom report (A7.13) — the columns and filters the user picked.
     *
     * Everything here is already computed by one of the fixed reports; what was
     * missing was any way to ask for a combination nobody anticipated. HR's
     * real questions are of the form "on-time percentage and overtime for the
     * night shift in the Leeds office" — three columns from two reports, which
     * previously meant exporting both and joining them by hand in Excel.
     *
     * The hours pass is skipped entirely unless an hours column was asked for.
     * It has to pair every punch on every day for every employee, which is
     * several times the work of the rest of the report, and most selections do
     * not want it.
     *
     * @param  array{columns?: array<int, string>, department_id?: int|null, work_mode?: string|null}  $options
     */
    public function custom(int $companyId, string $from, string $to, ?int $officeId = null, array $options = []): array
    {
        $columns = array_values(array_intersect(
            // Intersected against the catalogue in catalogue order, so the
            // report reads the same way however the checkboxes were clicked.
            self::customColumnKeys(),
            $options['columns'] ?? self::CUSTOM_DEFAULT,
        ));

        if ($columns === []) {
            $columns = self::CUSTOM_DEFAULT;
        }

        $departmentId = $options['department_id'] ?? null;
        $workMode     = $options['work_mode'] ?? null;

        $stats = $this->employeeStats($companyId, $from, $to, $officeId)
            ->filter(fn ($s) => ! $departmentId || $s['employee']->department_id === (int) $departmentId)
            ->filter(fn ($s) => ! $workMode || $s['employee']->work_mode === $workMode);

        // An Eloquent collection rather than the plain one map() hands back —
        // only the former can lazily load() the relations below.
        $employees = new EloquentCollection($stats->map(fn ($s) => $s['employee'])->values()->all());

        // employeeStats only loads what the fixed reports need. These two are
        // loaded on demand rather than added there, so late/outliers/department
        // do not pay for relations they never print.
        if (array_intersect($columns, ['designation', 'manager'])) {
            $employees->load('designation', 'manager');
        }

        $hours = $this->wantsHours($columns)
            ? $this->hoursByEmployee($employees, $from, $to)
            : [];

        $rows = $stats->map(function ($s) use ($columns, $hours) {
            $employee = $s['employee'];
            $h = $hours[$employee->id] ?? ['days_worked' => 0, 'regular' => 0, 'overtime' => 0, 'total' => 0];

            $values = [
                'code'        => $employee->employee_code,
                'name'        => $employee->full_name,
                'department'  => $employee->department->name ?? '—',
                'office'      => $employee->office->name ?? '—',
                'designation' => $employee->designation->name ?? '—',
                'manager'     => $employee->manager->full_name ?? '—',
                'work_mode'   => Employee::WORK_MODES[$employee->work_mode] ?? '—',
                'hire_date'   => $employee->hire_date?->toDateString() ?? '—',

                'present_days' => $s['present_days'],
                'leave_days'   => $s['leave_days'],
                'absent_days'  => $s['absent_days'],
                'late'         => $s['late'],
                'ontime'       => $s['ontime'],
                // Nobody clocked in at all is not 0% punctuality, it is no
                // reading — and a 0 would drag every average that touches it.
                'ontime_pct'   => $s['ontime_pct'] === null ? '—' : $s['ontime_pct'] . '%',

                'days_worked'    => $h['days_worked'],
                'regular_hours'  => $this->decimalHours($h['regular']),
                'overtime_hours' => $this->decimalHours($h['overtime']),
                'total_hours'    => $this->decimalHours($h['total']),
            ];

            $row = [];

            foreach ($columns as $key) {
                $row[self::customColumnLabel($key)] = $values[$key];
            }

            return $row;
        })->values()->all();

        return [
            'title'    => 'Custom Report',
            'subtitle' => sprintf('%d column(s) over %d employee(s)', count($columns), count($rows)),
            'tiles'    => [
                ['label' => 'Employees', 'value' => count($rows)],
                ['label' => 'Columns', 'value' => count($columns)],
            ],
            'headings' => array_map(fn ($key) => self::customColumnLabel($key), $columns),
            'rows'     => $rows,
            'columns'  => $columns,
        ];
    }

    /** Does this selection need the expensive punch-pairing pass? */
    protected function wantsHours(array $columns): bool
    {
        return (bool) array_intersect(
            $columns,
            ['days_worked', 'regular_hours', 'overtime_hours', 'total_hours'],
        );
    }

    /**
     * Worked, overtime and regular minutes per employee for the period.
     *
     * The same pairing payroll() does, lifted out so the custom builder can ask
     * for hours without asking for payroll's fixed set of columns.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @return array<int, array{days_worked: int, regular: int, overtime: int, total: int}>
     */
    protected function hoursByEmployee(EloquentCollection $employees, string $from, string $to): array
    {
        // employeeStats loads only what it needs; the schedule side has to be
        // pulled in here or every employee-day fires its own query for the shift.
        $employees->load(['department.shift', 'shiftOverride', 'shiftAssignments.shift']);

        $punches = AttendanceLog::whereIn('employee_id', $employees->pluck('id'))
            ->forDates($from, $to)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $out = [];

        foreach ($employees as $employee) {
            $byDate = $punches->get($employee->id, collect())
                ->groupBy(fn (AttendanceLog $log) => $log->work_date->toDateString());

            $total = 0;
            $overtime = 0;
            $daysWorked = 0;

            foreach ($byDate as $date => $dayLogs) {
                $result = $this->attendance->overtimeFor($employee, $date, $dayLogs);

                $total += $result['worked'];
                $overtime += $result['overtime'];

                if ($result['worked'] > 0) {
                    $daysWorked++;
                }
            }

            $out[$employee->id] = [
                'days_worked' => $daysWorked,
                'regular'     => $total - $overtime,
                'overtime'    => $overtime,
                'total'       => $total,
            ];
        }

        return $out;
    }

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
            ->forDates($from, $to)
            ->where('type', 'in')
            ->get()
            ->groupBy('employee_id');

        // Absence is measured against the days the company works, with approved
        // leave accounted for rather than held against anyone.
        $expected   = count($this->leave->workingDatesBetween(Company::find($companyId), $from, $to));
        $leaveDates = $this->leave->leaveDatesByEmployee($companyId, $from, $to);

        return $employees->map(function (Employee $e) use ($insByEmp, $expected, $leaveDates) {
            $ins = $insByEmp->get($e->id, collect());
            $presentDates = $ins->pluck('work_date')->map->toDateString()->unique();
            $presentDays = $presentDates->count();
            $late = $ins->where('status', 'late')->count();
            $ontime = $ins->where('status', 'ontime')->count();
            $totalIns = $ins->count();
            $ontimePct = $totalIns > 0 ? round($ontime / $totalIns * 100, 1) : null;

            $onLeave = collect($leaveDates[$e->id] ?? []);
            // Union so a day both worked and booked off is not deducted twice.
            $covered = $presentDates->merge($onLeave)->unique()->count();

            return [
                'employee'     => $e,
                'present_days' => $presentDays,
                'leave_days'   => $onLeave->count(),
                'absent_days'  => max(0, $expected - $covered),
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
                'Leave (emp-days)'   => $group->sum('leave_days'),
                'Absent (emp-days)'  => $group->sum('absent_days'),
                'Late Count'   => $late,
                'On-time %'    => $ontimePct . '%',
            ];
        })->sortByDesc('Employees')->values()->all();

        return [
            'title'    => 'Department Attendance Report',
            'subtitle' => 'Attendance rolled up by department. Approved leave is reported separately '
                . 'from absence, and neither counts weekends or company holidays.',
            'tiles'    => [
                ['label' => 'Departments', 'value' => count($rows)],
                ['label' => 'Employees', 'value' => $stats->count()],
                ['label' => 'Total Present Days', 'value' => $stats->sum('present_days')],
                ['label' => 'Total Leave Days', 'value' => $stats->sum('leave_days')],
                ['label' => 'Total Absent Days', 'value' => $stats->sum('absent_days')],
            ],
            'headings' => ['Department', 'Employees', 'Present (emp-days)', 'Leave (emp-days)',
                'Absent (emp-days)', 'Late Count', 'On-time %'],
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
