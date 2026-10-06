<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Support\DashboardWidgets;
use App\Support\QrLaunch;
use Illuminate\Http\Request;

/**
 * The dashboard, assembled per person (A8.4–A8.6).
 *
 * Each panel's data is gathered only when that panel is being shown. That is
 * the whole reason the widget list is consulted before the queries run rather
 * than after: an administrator who has turned off the approvals panel should
 * not be paying for three counts of it on every page load.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    public function index()
    {
        $user = auth()->user();
        $companyId = $this->companyId();

        $widgets = DashboardWidgets::forUser($user);
        $show = fn (string $key) => in_array($key, $widgets, true);

        $data = [
            'widgets'   => $widgets,
            'available' => DashboardWidgets::availableTo($user),
        ];

        // Shared by the tiles and the donut, so showing both costs one summary.
        $summary = ($show('tiles') || $show('attendance_donut'))
            ? $this->attendance->daySummary($companyId)
            : null;

        // Shared by the live board and the donut's absentee list.
        $live = ($show('who_is_in') || $show('attendance_donut'))
            ? $this->attendance->whoIsIn($companyId)
            : null;

        // The banner is not a panel: it is the page's heading, so it is always
        // drawn — but it is two counts, not two panels' worth of queries.
        $data['welcome'] = [
            'leave'           => LeaveRequest::where('company_id', $companyId)->pending()->count(),
            'regularisations' => AttendanceRegularisation::where('company_id', $companyId)
                ->where('status', 'pending')->count(),
            'now'             => $this->companyNow(),
        ];

        // The "Open QR screen" picker in the banner (A4.21) — for whoever runs
        // attendance, which is the permission the screens themselves sit behind.
        $data['qrLaunch'] = $user->can('manage-attendance') ? QrLaunch::forCompany($companyId) : null;

        if ($show('tiles')) {
            $previous = $this->previousWorkingDay($companyId);
            $before = $previous
                ? $this->attendance->daySummary($companyId, $previous->toDateString())
                : null;

            $data['stats'] = [
                'employees'   => Employee::where('company_id', $companyId)->active()->count(),
                'departments' => Department::where('company_id', $companyId)->count(),
                'offices'     => Office::where('company_id', $companyId)->count(),
                'present'     => $summary['present'],
                'late'        => $summary['late'],
                'on_leave'    => $summary['on_leave'],
                'absent'      => $summary['absent'],
                'rate'        => $this->attendanceRate($summary),
                // Measured against the last day people were expected in, not
                // the calendar's yesterday: on a Monday that is Friday, and a
                // comparison with an empty Sunday would read as a triumph.
                'versus'      => $previous?->format('D'),
                'before'      => $before ? $before + ['rate' => $this->attendanceRate($before)] : null,
            ];
        }

        if ($show('attendance_donut')) {
            $absentees = array_map(fn ($row) => $row['employee'], $live['not_in']);

            $data['donut'] = [
                'ontime'    => max(0, $summary['present'] - $summary['late']),
                'late'      => $summary['late'],
                'on_leave'  => $summary['on_leave'],
                'absent'    => $summary['absent'],
                'total'     => $summary['total'],
                'rate'      => $this->attendanceRate($summary),
                'absentees' => array_slice($absentees, 0, 6),
                'missing'   => count($absentees),
            ];
        }

        if ($show('week_comparison')) {
            $data['comparison'] = $this->weekComparison($companyId);
        }

        if ($show('attendance_trend')) {
            $month = $this->dailyTrend($companyId, 30);

            $data['trend'] = $month->slice(-7)->values();
            $data['trendMonth'] = $month;
        }

        if ($show('who_is_in')) {
            $board = $live;

            $data['board'] = [
                'in'       => count($board['in']),
                'on_break' => collect($board['in'])->where('on_break', true)->count(),
                'left'     => count($board['left']),
                'not_in'   => count($board['not_in']),
                'on_leave' => count($board['on_leave']),
                'missing'  => array_slice(
                    array_map(fn ($row) => $row['employee'], $board['not_in']),
                    0,
                    5,
                ),
            ];
        }

        if ($show('late_today')) {
            $data['lateToday'] = $this->lateToday($companyId);
        }

        if ($show('by_department')) {
            $data['byDepartment'] = $this->headcountByDepartment($companyId);
        }

        if ($show('pending_approvals')) {
            $data['approvals'] = [
                'leave'           => $data['welcome']['leave'],
                'regularisations' => $data['welcome']['regularisations'],
                'swaps'           => ShiftSwapRequest::where('company_id', $companyId)
                    ->where('status', 'pending')->count(),
                // Oldest first: the request that has waited longest is the one
                // somebody is about to chase.
                'requests'        => LeaveRequest::with(['employee', 'leaveType'])
                    ->where('company_id', $companyId)
                    ->pending()
                    ->orderBy('created_at')
                    ->limit(5)
                    ->get(),
            ];
        }

        if ($show('upcoming')) {
            $data['upcoming'] = $this->upcoming($companyId);
        }

        if ($show('birthdays')) {
            $data['birthdays'] = $this->birthdaysThisMonth($companyId);
        }

        if ($show('document_expiries')) {
            $data['expiries'] = EmployeeDocument::with('employee')
                ->where('company_id', $companyId)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', $this->companyNow()->addDays(EmployeeDocument::WARN_DAYS)->toDateString())
                ->orderBy('expires_on')
                ->limit(5)
                ->get();
        }

        if ($show('recent_activity')) {
            $data['recent'] = AttendanceLog::with(['employee', 'office'])
                ->where('company_id', $companyId)
                ->latest('scanned_at')
                ->limit(10)
                ->get();
        }

        if ($show('security')) {
            $staff = User::where('company_id', $companyId)->where('is_active', true)->get();

            // The same scope the activity log itself uses: this company's
            // events, plus the ones nobody can attribute — a guess at an
            // address that matches no account belongs to no company, and
            // hiding it would hide the most common attack there is. Another
            // company's attributed events are theirs, not ours.
            $mine = fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id');

            $data['security'] = [
                'failed_24h' => ActivityLog::where($mine)->where('event', ActivityLog::LOGIN_FAILED)
                    ->where('created_at', '>=', now()->subDay())->count(),
                'lockouts_24h' => ActivityLog::where($mine)->where('event', ActivityLog::LOCKOUT)
                    ->where('created_at', '>=', now()->subDay())->count(),
                // Counted over the accounts that can actually reach staff data.
                // "60% of everybody" is meaningless when most of everybody is
                // an employee who only sees their own attendance.
                'staff_total' => $staff->filter(fn (User $u) => $u->hasAnyRole(['admin', 'hr']))->count(),
                'staff_with_2fa' => $staff->filter(
                    fn (User $u) => $u->hasAnyRole(['admin', 'hr']) && $u->hasTwoFactor(),
                )->count(),
                'recent' => ActivityLog::with('user')
                    ->where($mine)
                    ->whereIn('event', [ActivityLog::LOGIN_FAILED, ActivityLog::LOCKOUT, ActivityLog::SETTINGS_CHANGED])
                    ->latest('created_at')
                    ->limit(5)
                    ->get(),
            ];
        }

        return view('dashboard', $data);
    }

    /** Save which panels this person wants (A8.5). */
    public function widgets(Request $request)
    {
        $user = $request->user();

        $chosen = array_values(array_intersect(
            (array) $request->input('widgets', []),
            array_keys(DashboardWidgets::availableTo($user)),
        ));

        $user->forceFill(['dashboard_widgets' => $chosen])->save();

        return back()->with('success', 'Dashboard updated.');
    }

    /**
     * This week against last (A8.6).
     *
     * Compared like for like: both windows run from Monday to the same weekday,
     * so a Tuesday morning is measured against the previous Monday–Tuesday and
     * not against a whole finished week. Without that, every Monday shows a
     * catastrophic collapse in attendance and every Friday a miraculous
     * recovery, which is the fastest way to teach people to ignore a trend.
     */
    protected function weekComparison(int $companyId): array
    {
        $today = $this->companyNow();
        $thisStart = $today->copy()->startOfWeek();
        $lastStart = $thisStart->copy()->subWeek();
        $lastEnd   = $lastStart->copy()->addDays($thisStart->diffInDays($today));

        $current  = $this->weekFigures($companyId, $thisStart->toDateString(), $today->toDateString());
        $previous = $this->weekFigures($companyId, $lastStart->toDateString(), $lastEnd->toDateString());

        $metrics = [];

        foreach (['present' => 'Days attended', 'late' => 'Late arrivals', 'absent' => 'Absences'] as $key => $label) {
            $now = $current[$key];
            $was = $previous[$key];

            $metrics[] = [
                'key'     => $key,
                'label'   => $label,
                'now'     => $now,
                'was'     => $was,
                'delta'   => $now - $was,
                // Percentage of nothing is not infinity, it is "no comparison".
                'percent' => $was > 0 ? (int) round((($now - $was) / $was) * 100) : null,
                // Fewer late arrivals is good; fewer days attended is not. The
                // view cannot know which, so it is decided here.
                'good'    => $key === 'present' ? ($now >= $was) : ($now <= $was),
            ];
        }

        return [
            'this_week' => $thisStart->format('M j') . ' – ' . $today->format('M j'),
            'last_week' => $lastStart->format('M j') . ' – ' . $lastEnd->format('M j'),
            'metrics'   => $metrics,
        ];
    }

    /** @return array{present: int, late: int, absent: int} */
    protected function weekFigures(int $companyId, string $from, string $to): array
    {
        $ins = AttendanceLog::where('company_id', $companyId)
            ->forDates($from, $to)
            ->where('type', 'in')
            ->get(['employee_id', 'work_date', 'status']);

        $present = $ins->map(fn ($log) => $log->employee_id . '|' . $log->work_date->toDateString())
            ->unique()->count();

        $headcount = Employee::where('company_id', $companyId)->active()->count();
        $workingDays = count($this->leave->workingDatesBetween(
            \App\Models\Company::find($companyId), $from, $to,
        ));

        $leaveDays = collect($this->leave->leaveDatesByEmployee($companyId, $from, $to))
            ->sum(fn ($dates) => count($dates));

        return [
            'present' => $present,
            'late'    => $ins->where('status', 'late')->count(),
            // Everything the company expected, less what was covered by
            // attendance or by approved leave.
            'absent'  => max(0, ($headcount * $workingDays) - $present - $leaveDays),
        ];
    }

    /**
     * Distinct people in per day, split into on time and late, oldest first.
     *
     * One query for the whole window rather than one per day — the old version
     * fired a count per day and used a raw work_date comparison that dropped
     * rows on any engine storing a time component with the date. A person's
     * day counts as late when their first clock-in of it was.
     *
     * @return \Illuminate\Support\Collection<int, array{date: string, label: string, short: string, count: int, late: int, ontime: int}>
     */
    protected function dailyTrend(int $companyId, int $days)
    {
        $today = $this->companyNow();

        $byDay = AttendanceLog::where('company_id', $companyId)
            ->forDates($today->copy()->subDays($days - 1)->toDateString(), $today->toDateString())
            ->where('type', 'in')
            ->orderBy('scanned_at')
            ->get(['employee_id', 'work_date', 'status', 'scanned_at'])
            ->groupBy(fn ($log) => $log->work_date->toDateString())
            ->map(function ($logs) {
                $firsts = $logs->unique('employee_id');

                return ['count' => $firsts->count(), 'late' => $firsts->where('status', 'late')->count()];
            });

        return collect(range($days - 1, 0))->map(function ($daysAgo) use ($byDay, $today) {
            $day = $today->copy()->subDays($daysAgo);
            $figures = $byDay[$day->toDateString()] ?? ['count' => 0, 'late' => 0];

            return [
                'date'   => $day->toDateString(),
                'label'  => $day->format('D'),
                'short'  => $day->format('M j'),
                'count'  => $figures['count'],
                'late'   => $figures['late'],
                'ontime' => $figures['count'] - $figures['late'],
            ];
        })->values();
    }

    /**
     * Present as a share of the people who were expected in: headcount less
     * approved leave. Null when nobody was expected — a rate of nothing is not
     * zero percent (trap 28).
     */
    protected function attendanceRate(array $summary): ?int
    {
        $expected = $summary['total'] - $summary['on_leave'];

        return $expected > 0 ? (int) min(100, round($summary['present'] / $expected * 100)) : null;
    }

    /** The most recent working day before today, inside the last fortnight. */
    protected function previousWorkingDay(int $companyId): ?\Carbon\Carbon
    {
        $today = $this->companyNow();

        $dates = $this->leave->workingDatesBetween(
            \App\Models\Company::find($companyId),
            $today->copy()->subDays(14)->toDateString(),
            $today->copy()->subDay()->toDateString(),
        );

        return $dates ? \Carbon\Carbon::parse(end($dates)) : null;
    }

    /**
     * Today's late arrivals, earliest first, one row per person — their first
     * clock-in of the day is the one that decides it.
     *
     * @return array{count: int, rows: array<int, array{log: AttendanceLog, minutes: int}>}
     */
    protected function lateToday(int $companyId): array
    {
        $today = $this->companyNow()->toDateString();

        $logs = AttendanceLog::with(['employee.department', 'employee.company'])
            ->where('company_id', $companyId)
            ->forDates($today, $today)
            ->where('type', 'in')
            ->orderBy('scanned_at')
            ->get()
            ->unique('employee_id')
            ->where('status', 'late')
            ->values();

        return [
            'count' => $logs->count(),
            // Minutes come from the same arithmetic that marked the punch late,
            // so the badge and the status can never disagree.
            'rows'  => $logs->take(6)->map(fn (AttendanceLog $log) => [
                'log'     => $log,
                'minutes' => $log->employee
                    ? $this->attendance->lateMinutes($log->employee, $log->scanned_at, $log->work_date->toDateString())
                    : 0,
            ])->all(),
        ];
    }

    /** @return array{departments: \Illuminate\Support\Collection, unassigned: int, total: int} */
    protected function headcountByDepartment(int $companyId): array
    {
        $departments = Department::where('company_id', $companyId)
            ->withCount(['employees as headcount' => fn ($q) => $q->active()])
            ->orderByDesc('headcount')
            ->orderBy('name')
            ->get(['id', 'name']);

        $unassigned = Employee::where('company_id', $companyId)->active()->whereNull('department_id')->count();

        return [
            'departments' => $departments,
            'unassigned'  => $unassigned,
            'total'       => $departments->sum('headcount') + $unassigned,
        ];
    }

    /**
     * Approved leave touching the next fortnight, and the next few holidays.
     *
     * @return array{leave: \Illuminate\Support\Collection, holidays: array<string, string>}
     */
    protected function upcoming(int $companyId): array
    {
        $today = $this->companyNow();

        $holidays = Holiday::namedBetween($companyId, $today->toDateString(), $today->copy()->addDays(90)->toDateString());
        ksort($holidays);

        return [
            'leave' => LeaveRequest::with(['employee', 'leaveType'])
                ->where('company_id', $companyId)
                ->approved()
                ->overlapping($today->toDateString(), $today->copy()->addDays(14)->toDateString())
                ->orderBy('start_date')
                ->limit(6)
                ->get(),
            'holidays' => array_slice($holidays, 0, 4, true),
        ];
    }

    /** Active employees born this month, in the order their days come round. */
    protected function birthdaysThisMonth(int $companyId)
    {
        $today = $this->companyNow();

        return Employee::with('department')
            ->where('company_id', $companyId)
            ->active()
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $today->month)
            ->get()
            ->sortBy(fn (Employee $e) => $e->date_of_birth->day)
            ->values()
            ->take(8);
    }
}
