<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\ReportService;
use App\Support\Clock;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Attendance history — the register, and one person's days inside it.
 *
 * Read-only, and deliberately so. `/attendance/logs` already lists punches and
 * `/reports/*` already aggregates periods; what neither could answer is the
 * question somebody actually asks — *what did this person's day look like*.
 * Corrections still go through `AttendanceController` and the regularisation
 * queue, which are the only doors into an append-only table.
 *
 * Nothing here computes attendance. The day rows come from
 * `AttendanceService::dayRows()` (shared with the app's `/attendance/history`)
 * and the per-employee totals from `ReportService::attendanceHistory()`, which
 * composes the two aggregates the fixed reports were already using. A screen
 * with its own arithmetic is how two pages come to disagree about the same
 * month.
 */
class AttendanceHistoryController extends Controller
{
    /**
     * The longest window either screen will build.
     *
     * `dayRows()` walks a row per day, so a range is work rather than a filter.
     * A year and a day covers "last year" plus an inclusive end; past that the
     * answer is a report, not a history.
     */
    protected const MAX_RANGE_DAYS = 366;

    /**
     * Sortable columns, and the row key each one reads.
     *
     * A whitelist rather than the request's word for it: the value picks a key
     * out of a computed array, and an unchecked one is an easy way to sort by
     * something that is not there and get nulls for a whole page.
     */
    protected const SORTS = [
        'name'       => 'name',
        'code'       => 'code',
        'department' => 'department',
        'present'    => 'present_days',
        'absent'     => 'absent_days',
        'late'       => 'late',
        'early'      => 'early_leave',
        'worked'     => 'worked_minutes',
        'break'      => 'break_minutes',
    ];

    public function __construct(
        protected AttendanceService $attendance,
        protected ReportService $reports,
    ) {}

    /**
     * Every employee, with their attendance summarised over the window.
     */
    public function index(Request $request)
    {
        $companyId = $this->companyId();
        [$from, $to] = $this->window($request);

        $filters = [
            'office_id'     => $request->filled('office_id') ? (int) $request->office_id : null,
            'department_id' => $request->filled('department_id') ? (int) $request->department_id : null,
            'q'             => $request->filled('q') ? trim((string) $request->q) : null,
        ];

        $rows = $this->reports
            ->attendanceHistory($companyId, $from, $to, $filters)
            ->map(fn (array $row) => [
                'employee'       => $row['employee'],
                'name'           => $row['employee']->full_name,
                'code'           => $row['employee']->employee_code,
                'department'     => $row['employee']->department?->name,
                'designation'    => $row['employee']->designation?->name,
                'present_days'   => $row['present_days'],
                'absent_days'    => $row['absent_days'],
                'late'           => $row['late'],
                'early_leave'    => $row['early_leave'],
                'worked_minutes' => $row['worked_minutes'],
                'break_minutes'  => $row['break_minutes'],
            ]);

        $sort = self::SORTS[$request->input('sort')] ?? 'name';
        $dir  = $request->input('dir') === 'desc' ? 'desc' : 'asc';

        $rows = $dir === 'desc'
            ? $rows->sortByDesc($sort, SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $rows->sortBy($sort, SORT_NATURAL | SORT_FLAG_CASE)->values();

        if ($request->input('export')) {
            return $this->exportIndex($request, $rows, $from, $to);
        }

        return view('attendance.history.index', [
            'rows'        => $this->paginate($request, $rows),
            'totals'      => $this->registerTotals($rows),
            'from'        => $from,
            'to'          => $to,
            'sort'        => $request->input('sort') ?: 'name',
            'dir'         => $dir,
            'offices'     => Office::where('company_id', $companyId)->orderBy('name')->get(),
            'departments' => Department::where('company_id', $companyId)->orderBy('name')->get(),
        ]);
    }

    /**
     * One employee's days, newest first.
     *
     * The same order the app's own history uses, and for the same reason: the
     * question is nearly always about a day that has just happened. `dayRows()`
     * reads forwards because a running total has to, so the reversal is here.
     */
    public function show(Request $request, Employee $employee)
    {
        // Route-model binding does not know about companies. Without this an id
        // typed into the address bar reads another company's attendance.
        abort_unless($employee->company_id === $this->companyId(), 404);

        $view = in_array($request->input('view'), ['daily', 'weekly', 'monthly', 'custom'], true)
            ? $request->input('view')
            : 'daily';

        [$from, $to] = $this->window($request, $view);

        $days = $this->attendance->dayRows($employee, $from, $to);

        if ($request->input('export')) {
            return $this->exportShow($request, $employee, $days, $from, $to);
        }

        return view('attendance.history.show', [
            'employee' => $employee->load(['department', 'designation', 'office']),
            'days'      => $days->reverse()->values(),
            'groups'    => $this->groupsFor($view, $days, $from, $to),
            'totals'    => $this->periodTotals($days),
            'view'      => $view,
            'from'      => $from,
            'to'        => $to,
        ]);
    }

    // -------------------------------------------------------------------------
    // The window
    // -------------------------------------------------------------------------

    /**
     * The date range both screens work over.
     *
     * Validated as `Y-m-d` rather than `date`, and not only because a loose
     * date makes a nonsense range: both ends are concatenated into the export
     * filename below, and a filename is a path. maatwebsite/excel wrote outside
     * its configured disk for exactly this reason through 3.1.69
     * (CVE-2026-84374) — the dependency is current, this is the half that stays
     * true after the next upgrade.
     *
     * `validate()` returns an array without a key the caller never sent, so
     * every read is `?? null` (trap 2).
     *
     * @return array{0: string, 1: string}
     */
    protected function window(Request $request, string $view = 'daily'): array
    {
        $dates = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d',
        ]);

        $today = $this->companyNow()->toDateString();

        $default = match ($view) {
            'weekly'  => [$this->companyNow()->startOfWeek()->toDateString(), $this->companyNow()->endOfWeek()->toDateString()],
            'monthly' => [$this->companyNow()->startOfMonth()->toDateString(), $this->companyNow()->endOfMonth()->toDateString()],
            default   => [$this->companyNow()->startOfMonth()->toDateString(), $today],
        };

        $from = ($dates['from'] ?? null) ?: $default[0];
        $to   = ($dates['to'] ?? null) ?: $default[1];

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // A day that has not happened cannot be an absence, so the register
        // never counts forward of today even when a month runs past it.
        if ($to > $today) {
            $to = $today;
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_RANGE_DAYS) {
            $from = Carbon::parse($to)->subDays(self::MAX_RANGE_DAYS)->toDateString();
        }

        return [$from, $to];
    }

    // -------------------------------------------------------------------------
    // Totals
    // -------------------------------------------------------------------------

    /** @param  Collection<int, array<string, mixed>>  $rows */
    protected function registerTotals(Collection $rows): array
    {
        return [
            'employees'      => $rows->count(),
            'present_days'   => $rows->sum('present_days'),
            'absent_days'    => $rows->sum('absent_days'),
            'late'           => $rows->sum('late'),
            'early_leave'    => $rows->sum('early_leave'),
            'worked_minutes' => $rows->sum('worked_minutes'),
            'break_minutes'  => $rows->sum('break_minutes'),
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $days */
    protected function periodTotals(Collection $days): array
    {
        return [
            'present_days'   => $days->where('status', 'present')->count(),
            'absent_days'    => $days->where('status', 'absent')->count(),
            'leave_days'     => $days->where('status', 'leave')->count(),
            'late'           => $days->where('late', true)->count(),
            'early_leave'    => $days->where('early_leave', true)->count(),
            'worked_minutes' => $days->sum('worked_minutes'),
            'break_minutes'  => $days->sum('break_minutes'),
        ];
    }

    /**
     * Sub-totals drawn between the day rows, or none.
     *
     * The daily view has nothing to group by. The weekly and monthly ones do,
     * but they still list every day — a summary that hides the days it was
     * computed from cannot be checked against the punches, which is the whole
     * reason somebody opens this screen.
     *
     * @param  Collection<int, array<string, mixed>>  $days
     * @return Collection<int, array{label: string, days: Collection, totals: array}>
     */
    protected function groupsFor(string $view, Collection $days, string $from, string $to): Collection
    {
        $key = match ($view) {
            'weekly'  => null,
            'monthly' => fn (array $day) => Carbon::parse($day['date'])->startOfWeek()->toDateString(),
            'custom'  => Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31
                ? fn (array $day) => Carbon::parse($day['date'])->format('Y-m')
                : null,
            default   => null,
        };

        if ($key === null) {
            return collect();
        }

        return $days->groupBy($key)
            ->map(fn (Collection $group, string $label) => [
                'label'  => $view === 'monthly'
                    ? 'Week of ' . Carbon::parse($label)->format('d M Y')
                    : Carbon::parse($label . '-01')->format('F Y'),
                'days'   => $group->reverse()->values(),
                'totals' => $this->periodTotals($group),
            ])
            ->sortKeysDesc()
            ->values();
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /**
     * Page a collection that was computed rather than queried.
     *
     * The aggregates are built in PHP — `overtimeFor` reads a shift policy per
     * employee-day — so there is no query to put a LIMIT on. Slicing here keeps
     * the page size honest to `config/pagination.php` all the same.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    protected function paginate(Request $request, Collection $rows): LengthAwarePaginator
    {
        $perPage = $this->perPage('attendance');
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    // -------------------------------------------------------------------------
    // Exports — the same {headings, rows} pair the reports hand to TableExport
    // -------------------------------------------------------------------------

    /** @param  Collection<int, array<string, mixed>>  $rows */
    protected function exportIndex(Request $request, Collection $rows, string $from, string $to)
    {
        abort_unless($request->user()->can('export-reports'), 403);

        $headings = [
            'Employee', 'Staff No.', 'Department', 'Designation', 'Present Days',
            'Absent Days', 'Late Days', 'Early Check-outs', 'Total Working Hours',
            'Total Break Time',
        ];

        $table = $rows->map(fn (array $row) => [
            'Employee'            => $row['name'],
            'Staff No.'           => $row['code'],
            'Department'          => $row['department'] ?? '—',
            'Designation'         => $row['designation'] ?? '—',
            'Present Days'        => $row['present_days'],
            'Absent Days'         => $row['absent_days'],
            'Late Days'           => $row['late'],
            'Early Check-outs'    => $row['early_leave'],
            'Total Working Hours' => Clock::duration($row['worked_minutes']),
            'Total Break Time'    => Clock::duration($row['break_minutes']),
        ])->all();

        return $this->download(
            $request, $headings, $table,
            'attendance-history_' . $from . '_to_' . $to,
            'Attendance History', $from, $to,
        );
    }

    /** @param  Collection<int, array<string, mixed>>  $days */
    protected function exportShow(Request $request, Employee $employee, Collection $days, string $from, string $to)
    {
        abort_unless($request->user()->can('export-reports'), 403);

        $headings = [
            'Date', 'Day', 'Check-in', 'Break Start', 'Break End', 'Total Break',
            'Check-out', 'Total Working', 'Status', 'Late/Early', 'Remarks',
        ];

        $table = $days->reverse()->values()->map(fn (array $day) => [
            'Date'          => $day['date'],
            'Day'           => $day['weekday'],
            'Check-in'      => $day['first_in'] ? Clock::time($day['first_in']) : '—',
            'Break Start'   => $day['break_start'] ? Clock::time($day['break_start']) : '—',
            'Break End'     => $day['break_end'] ? Clock::time($day['break_end']) : '—',
            'Total Break'   => Clock::duration($day['break_minutes']),
            'Check-out'     => $day['last_out'] ? Clock::time($day['last_out']) : '—',
            'Total Working' => Clock::duration($day['worked_minutes']),
            'Status'        => ucfirst(str_replace('_', ' ', $day['status'])),
            'Late/Early'    => trim(($day['late'] ? 'Late ' : '') . ($day['early_leave'] ? 'Left early' : '')) ?: '—',
            'Remarks'       => $day['remarks'] ?? ($day['holiday'] ?? '—'),
        ])->all();

        return $this->download(
            $request, $headings, $table,
            'attendance_' . $employee->employee_code . '_' . $from . '_to_' . $to,
            $employee->full_name . ' — Attendance', $from, $to,
        );
    }

    /**
     * Screen, spreadsheet and PDF from one {headings, rows} pair.
     *
     * The same contract the fixed reports use, so `TableExport` and
     * `reports.pdf` render this without knowing anything about attendance.
     * Row keys must match `$headings` exactly or the exports throw.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function download(Request $request, array $headings, array $rows, string $slug, string $title, string $from, string $to)
    {
        if ($request->input('export') === 'excel') {
            return Excel::download(new TableExport($headings, $rows), $slug . '.xlsx');
        }

        $officeId = $request->filled('office_id') ? (int) $request->office_id : null;

        return Pdf::loadView('reports.pdf', [
            'title'    => $title,
            'subtitle' => $from . ' to ' . $to,
            'tiles'    => [],
            'headings' => $headings,
            'rows'     => $rows,
            'company'  => Company::find($this->companyId()),
            'office'   => $officeId ? Office::find($officeId) : null,
            'from'     => $from,
            'to'       => $to,
        ])->setPaper('a4', 'landscape')->download($slug . '.pdf');
    }
}
