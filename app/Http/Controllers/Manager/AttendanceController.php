<?php

namespace App\Http\Controllers\Manager;

use App\Models\AttendanceLog;
use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use Illuminate\Http\Request;

/**
 * The team's punch history.
 *
 * Read-only, and that is a decision rather than an omission. Keying a punch in
 * by hand and striking one out are both `manage-attendance`, which the manager
 * role does not hold: attendance is append-only and every write records an
 * actor, so the people who can alter the record are deliberately the small set
 * whose changes HR will stand behind. A manager who spots a wrong punch has two
 * routes that already exist — the employee raises a regularisation (A4.13), or
 * HR corrects it (A4.12) — and both leave a trail this screen would not.
 */
class AttendanceController extends Controller
{
    public function __construct(
        ManagerScope $scope,
        protected TeamAttendance $teamAttendance,
    ) {
        parent::__construct($scope);
    }

    /** Today's team board — who is in, who is late, who is unaccounted for. */
    public function index(Request $request)
    {
        $manager = $this->manager();
        $timezone = $this->timezone($manager);
        $today = now($timezone)->toDateString();

        $data = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        // validate() drops an absent nullable key entirely, so the fallback has
        // to survive the key not being there at all.
        $date = ($data['date'] ?? null) ?: $today;

        // A day that has not happened cannot be reported on, and calling anyone
        // absent for it would be a claim they cannot answer.
        if ($date > $today) {
            $date = $today;
        }

        $team = $this->scope->team($manager, ['department', 'office', 'shiftOverride', 'department.shift']);
        $rows = $this->teamAttendance->forDate($team, $date, $manager->company_id);

        return view('manager.attendance.index', [
            'manager'  => $manager,
            'date'     => $date,
            'today'    => $today,
            'timezone' => $timezone,
            'rows'     => $rows,
            'summary'  => $this->teamAttendance->summary($rows),
        ]);
    }

    /** The filterable punch log, scoped to the team and nobody else. */
    public function logs(Request $request)
    {
        $manager = $this->manager();
        $teamIds = $this->scope->teamIds($manager);

        $logs = AttendanceLog::with(['employee', 'office'])
            // An empty team yields an empty `whereIn`, which matches nothing —
            // the right answer for a manager with no reports, and never "all".
            ->whereIn('employee_id', $teamIds)
            ->when($request->filled('date'), fn ($q) => $q->whereDate('work_date', $request->date))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('employee_id'), function ($q) use ($request, $teamIds) {
                // Narrowing, never widening: an id outside the team filters the
                // result to nothing rather than reaching past the scope.
                $wanted = (int) $request->employee_id;

                return $q->where('employee_id', in_array($wanted, $teamIds, true) ? $wanted : 0);
            })
            ->latest('scanned_at')
            ->paginate(25)
            ->withQueryString();

        return view('manager.attendance.logs', [
            'manager' => $manager,
            'logs'    => $logs,
            'team'    => $this->scope->team($manager),
        ]);
    }
}
