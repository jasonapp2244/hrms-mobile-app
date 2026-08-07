<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Support\DashboardWidgets;
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
        $companyId = $user->company_id ?? Office::value('company_id');

        $widgets = DashboardWidgets::forUser($user);
        $show = fn (string $key) => in_array($key, $widgets, true);

        $data = [
            'widgets'   => $widgets,
            'available' => DashboardWidgets::availableTo($user),
        ];

        if ($show('tiles')) {
            $summary = $this->attendance->daySummary($companyId);

            $data['stats'] = [
                'employees'   => Employee::where('company_id', $companyId)->active()->count(),
                'departments' => Department::where('company_id', $companyId)->count(),
                'offices'     => Office::where('company_id', $companyId)->count(),
                'present'     => $summary['present'],
                'late'        => $summary['late'],
                'on_leave'    => $summary['on_leave'],
                'absent'      => $summary['absent'],
            ];
        }

        if ($show('week_comparison')) {
            $data['comparison'] = $this->weekComparison($companyId);
        }

        if ($show('attendance_trend')) {
            $data['trend'] = $this->sevenDayTrend($companyId);
        }

        if ($show('who_is_in')) {
            $board = $this->attendance->whoIsIn($companyId);

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

        if ($show('pending_approvals')) {
            $data['approvals'] = [
                'leave'           => LeaveRequest::where('company_id', $companyId)->pending()->count(),
                'regularisations' => \App\Models\AttendanceRegularisation::where('company_id', $companyId)
                    ->where('status', 'pending')->count(),
                'swaps'           => ShiftSwapRequest::where('company_id', $companyId)
                    ->where('status', 'pending')->count(),
            ];
        }

        if ($show('document_expiries')) {
            $data['expiries'] = EmployeeDocument::with('employee')
                ->where('company_id', $companyId)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->addDays(EmployeeDocument::WARN_DAYS)->toDateString())
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

            $data['security'] = [
                'failed_24h' => ActivityLog::where('event', ActivityLog::LOGIN_FAILED)
                    ->where('created_at', '>=', now()->subDay())->count(),
                'lockouts_24h' => ActivityLog::where('event', ActivityLog::LOCKOUT)
                    ->where('created_at', '>=', now()->subDay())->count(),
                // Counted over the accounts that can actually reach staff data.
                // "60% of everybody" is meaningless when most of everybody is
                // an employee who only sees their own attendance.
                'staff_total' => $staff->filter(fn (User $u) => $u->hasAnyRole(['admin', 'hr']))->count(),
                'staff_with_2fa' => $staff->filter(
                    fn (User $u) => $u->hasAnyRole(['admin', 'hr']) && $u->hasTwoFactor(),
                )->count(),
                'recent' => ActivityLog::with('user')
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
        $today = now();
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

    /** Distinct people in, per day, for the last seven days. */
    protected function sevenDayTrend(int $companyId)
    {
        // One query, grouped, rather than seven — the old version fired a count
        // per day and used a raw work_date comparison that dropped rows on any
        // engine storing a time component with the date.
        $counts = AttendanceLog::where('company_id', $companyId)
            ->forDates(now()->subDays(6)->toDateString(), now()->toDateString())
            ->where('type', 'in')
            ->get(['employee_id', 'work_date'])
            ->groupBy(fn ($log) => $log->work_date->toDateString())
            ->map(fn ($logs) => $logs->pluck('employee_id')->unique()->count());

        return collect(range(6, 0))->map(function ($daysAgo) use ($counts) {
            $day = now()->subDays($daysAgo);

            return [
                'label' => $day->format('D'),
                'count' => $counts[$day->toDateString()] ?? 0,
            ];
        });
    }
}
