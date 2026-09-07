<?php

namespace App\Http\Controllers\Manager;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Services\LeaveService;
use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use Illuminate\Http\Request;

/**
 * The people who report to this manager.
 *
 * Read-only, and narrower than the HR employee screen on purpose. A supervisor
 * needs to know who is on their team, what shift they are on and whether they
 * turned up; they do not need somebody's national ID number, home address, date
 * of birth or passport scan. Those are on the HR record behind
 * `manage-employees`, which this role does not hold and this area never asks
 * for — a manager reaching them would be a data-protection problem dressed up
 * as a convenience.
 *
 * Editing lives with HR for the same reason. A manager who could change a
 * department could move somebody into a different shift pattern, and one who
 * could change `manager_id` could widen their own scope — which is the whole
 * thing the scope exists to prevent.
 */
class TeamController extends Controller
{
    /** Days of attendance shown on one person's page. */
    protected const HISTORY_DAYS = 30;

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

        $team = $this->scope->team($manager, ['department', 'office', 'designation', 'shiftOverride', 'department.shift']);

        $rows = $this->teamAttendance->forDate($team, $today, $manager->company_id);

        // Searching a team of twelve in PHP costs nothing and keeps the scope
        // query in one place — a `when()` chain on the scoped builder would be
        // one more spot where the manager filter could be dropped by accident.
        if ($search = trim((string) $request->input('q'))) {
            $needle = mb_strtolower($search);

            $rows = $rows->filter(function (array $row) use ($needle) {
                $employee = $row['employee'];

                return str_contains(mb_strtolower($employee->full_name), $needle)
                    || str_contains(mb_strtolower((string) $employee->employee_code), $needle)
                    || str_contains(mb_strtolower((string) $employee->department?->name), $needle);
            })->values();
        }

        return view('manager.team.index', [
            'manager'  => $manager,
            'today'    => $today,
            // Punches are stored in UTC; every clock face on screen has to be
            // converted into the company's zone or a night shift reads as the
            // wrong day.
            'timezone' => $timezone,
            'rows'     => $rows,
            'summary' => $this->teamAttendance->summary($rows),
            'search'  => $search ?? '',
            'total'   => $team->count(),
        ]);
    }

    /**
     * One team member.
     *
     * The route-model binding resolves any employee in the table, so the scope
     * check is the only thing standing between this and every record in the
     * company. It runs before anything is read.
     */
    public function show(Request $request, Employee $employee)
    {
        $manager = $this->manager();
        $this->scope->assertManages($manager, $employee);

        $employee->load(['department', 'office', 'designation', 'shiftOverride', 'department.shift']);

        $timezone = $this->timezone($manager);
        $today = now($timezone)->toDateString();
        $from  = now($timezone)->subDays(self::HISTORY_DAYS - 1)->toDateString();

        $logs = AttendanceLog::with('office')
            ->where('employee_id', $employee->id)
            // forDates, never whereBetween on a date cast.
            ->forDates($from, $today)
            ->orderByDesc('scanned_at')
            ->get();

        // Leave is shown whatever its state — a manager needs to see what they
        // approved as much as what is still waiting on them.
        $leave = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->limit(10)
            ->get();

        $schedule = ShiftAssignment::with('shift')
            ->where('employee_id', $employee->id)
            ->published()
            ->between($today, now($timezone)->addDays(13)->toDateString())
            ->orderBy('date')
            ->get();

        return view('manager.team.show', [
            'manager'  => $manager,
            'employee' => $employee,
            'timezone' => $timezone,
            'today'    => $today,
            'from'     => $from,
            'logs'     => $logs,
            'leave'    => $leave,
            'schedule' => $schedule,
            'balances' => $this->leave->balanceSummary($employee),
        ]);
    }
}
