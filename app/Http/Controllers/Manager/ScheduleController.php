<?php

namespace App\Http\Controllers\Manager;

use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The team's published roster.
 *
 * Read-only. Planning the roster is `manage-shifts`, which the manager role
 * does not hold and which this area does not ask for: a grid planner already
 * exists behind that permission (A5.8) and a second, team-shaped editor would
 * be a second way to write shift_assignments, with its own opinion about
 * publishing. One planner, one publish step.
 *
 * What a manager needs from the roster is the read: who is on, which days are
 * short, and whether the week ahead is actually covered. That is what this
 * gives, and the coverage gaps are on the dashboard.
 */
class ScheduleController extends Controller
{
    /** A fortnight — two roster cycles, and it still fits on a laptop screen. */
    protected const DAYS = 14;

    public function __construct(
        ManagerScope $scope,
        protected TeamAttendance $teamAttendance,
    ) {
        parent::__construct($scope);
    }

    public function index(Request $request)
    {
        $manager = $this->manager();
        $timezone = $this->timezone($manager);

        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
        ]);

        // An absent nullable key is dropped by validate() entirely, so the
        // fallback has to cope with the key not existing rather than with null.
        $from = Carbon::parse(($data['from'] ?? null) ?: now($timezone)->toDateString());
        $to   = $from->copy()->addDays(self::DAYS - 1);

        $team = $this->scope->team($manager, ['department', 'shiftOverride', 'department.shift']);

        $rows = $this->teamAttendance->roster(
            $team,
            $from->toDateString(),
            $to->toDateString(),
            $manager->company_id,
        );

        // The column headings, built once rather than re-derived per row.
        $dates = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $dates[] = [
                'date'  => $day->toDateString(),
                'label' => $day->format('D'),
                'day'   => $day->format('j M'),
            ];
        }

        return view('manager.schedule.index', [
            'manager' => $manager,
            'from'    => $from->toDateString(),
            'to'      => $to->toDateString(),
            'prev'    => $from->copy()->subDays(self::DAYS)->toDateString(),
            'next'    => $from->copy()->addDays(self::DAYS)->toDateString(),
            'dates'   => $dates,
            'rows'    => $rows,
        ]);
    }
}
