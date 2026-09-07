<?php

namespace App\Http\Controllers\Manager;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwapRequest;
use App\Services\LeaveService;
use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use Illuminate\Http\Request;

/**
 * The manager's home screen.
 *
 * Deliberately not a copy of the admin dashboard. That one answers "how is the
 * company doing" — headcount, week-on-week trend, failed sign-ins — and a team
 * lead can do nothing with any of it. This one answers "what do I have to deal
 * with before I get on with my shift", so every panel is either somebody who is
 * not where they should be, or something waiting on this manager's decision.
 *
 * Every figure is counted from a real row. There are no placeholder tiles: a
 * metric this data model cannot answer honestly is left off the screen rather
 * than filled in, because a supervisor who catches one invented number stops
 * believing the others.
 */
class DashboardController extends Controller
{
    /** How far back to look for a shift somebody never clocked out of. */
    protected const MISSING_CHECKOUT_DAYS = 14;

    /** How far ahead the coverage strip runs. */
    protected const COVERAGE_DAYS = 7;

    public function __construct(
        ManagerScope $scope,
        protected TeamAttendance $teamAttendance,
        protected LeaveService $leave,
    ) {
        parent::__construct($scope);
    }

    public function index(Request $request)
    {
        $manager = $this->manager();
        $timezone = $this->timezone($manager);
        $today = now($timezone)->toDateString();

        $team = $this->scope->team($manager, ['department', 'office', 'shiftOverride', 'department.shift']);

        // An empty team is a legitimate state, not an error: the role grants the
        // gate and `employees.manager_id` decides the scope, so a manager nobody
        // reports to yet gets a working screen that says so.
        $rows = $this->teamAttendance->forDate($team, $today, $manager->company_id);

        return view('manager.dashboard', [
            'manager'  => $manager,
            'today'    => $today,
            'timezone' => $timezone,
            'team'     => $team,
            'rows'     => $rows,
            'summary'  => $this->teamAttendance->summary($rows),

            'missingCheckouts' => $this->teamAttendance->missingCheckouts(
                $team,
                now($timezone)->subDays(self::MISSING_CHECKOUT_DAYS)->toDateString(),
                $today,
            ),

            'pendingLeave' => $this->pendingLeave($manager),
            'pendingSwaps' => $this->pendingSwaps($manager),
            'coverage'     => $this->coverage($manager, $team, $today, $timezone),
            'recent'       => $this->recentPunches($team),
        ]);
    }

    /**
     * Leave this manager personally still has to decide.
     *
     * Filtered by `isAwaitingManager` exactly as the approvals inbox is —
     * anything already passed up is HR's and showing it here would have a
     * manager chasing a decision that is no longer theirs to make.
     */
    protected function pendingLeave(\App\Models\Employee $manager)
    {
        return LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('employee_id', $this->scope->teamIds($manager))
            ->pending()
            ->orderBy('start_date')
            ->get()
            ->filter(fn (LeaveRequest $r) => $r->isAwaitingManager())
            ->values();
    }

    /**
     * Swaps two people have agreed that now need a manager's sanction.
     *
     * Gated on `approve-swaps` rather than assumed from the role: the roles
     * editor can take that permission away, and a panel offering a decision the
     * next click refuses is worse than no panel.
     */
    protected function pendingSwaps(\App\Models\Employee $manager)
    {
        if (! auth()->user()->can('approve-swaps')) {
            return collect();
        }

        $teamIds = $this->scope->teamIds($manager);

        return ShiftSwapRequest::with('requester', 'target')
            ->awaitingApproval()
            ->where(fn ($q) => $q->whereIn('requester_id', $teamIds)->orWhereIn('target_id', $teamIds))
            // Never their own trade. Approving a swap you are standing in is not
            // an approval, and ShiftSwapController refuses it anyway.
            ->where('requester_id', '!=', $manager->id)
            ->where('target_id', '!=', $manager->id)
            ->orderBy('requester_date')
            ->get();
    }

    /**
     * How many of the team are rostered on, each day for the coming week.
     *
     * Published assignments only — the same rule the employee's own schedule
     * and the app's `/team/roster` follow. A manager planning against draft days
     * would tell somebody to come in on a shift that is still being moved
     * around, which is the entire reason the publish step exists.
     *
     * Leave outranks a rostered day, so the count is who will actually turn up
     * rather than who was pencilled in before they booked the day off.
     */
    protected function coverage(\App\Models\Employee $manager, $team, string $today, string $timezone): array
    {
        if ($team->isEmpty()) {
            return [];
        }

        $from = $today;
        $to   = now($timezone)->addDays(self::COVERAGE_DAYS - 1)->toDateString();

        $assignments = ShiftAssignment::whereIn('employee_id', $team->pluck('id'))
            // The model scope, not whereBetween — `date` is a date cast.
            ->between($from, $to)
            ->whereNotNull('published_at')
            ->get()
            ->groupBy(fn (ShiftAssignment $a) => $a->date->toDateString());

        $holidays = $manager->company
            ? Holiday::namedBetween($manager->company_id, $from, $to)
            : [];

        $working = array_flip($this->leave->workingDatesBetween($manager->company, $from, $to));
        $leaveByEmployee = $this->leave->leaveDatesByEmployee($manager->company_id, $from, $to);

        // Inverted once rather than scanned per employee per day.
        $onLeave = [];
        foreach ($leaveByEmployee as $employeeId => $dates) {
            foreach ($dates as $date) {
                $onLeave[$date][$employeeId] = true;
            }
        }

        $days = [];

        for ($day = \Carbon\Carbon::parse($from); $day->lte(\Carbon\Carbon::parse($to)); $day->addDay()) {
            $date = $day->toDateString();
            $planned = $assignments->get($date, collect());

            $rostered = $planned->where('is_day_off', false)
                ->reject(fn (ShiftAssignment $a) => isset($onLeave[$date][$a->employee_id]))
                ->count();

            $days[] = [
                'date'       => $date,
                'label'      => $day->format('D'),
                'day'        => $day->format('j M'),
                'is_working' => isset($working[$date]),
                'holiday'    => $holidays[$date] ?? null,
                'rostered'   => $rostered,
                'on_leave'   => count($onLeave[$date] ?? []),
                // Nothing published for a working day is a real gap and is
                // reported as one, rather than being drawn as a zero that looks
                // like a decision somebody made.
                'unplanned'  => $planned->isEmpty() && isset($working[$date]),
            ];
        }

        return $days;
    }

    /** The team's last few punches, so a manager can see the day moving. */
    protected function recentPunches($team)
    {
        if ($team->isEmpty()) {
            return collect();
        }

        return AttendanceLog::with(['employee', 'office'])
            ->whereIn('employee_id', $team->pluck('id'))
            ->latest('scanned_at')
            ->limit(10)
            ->get();
    }
}
