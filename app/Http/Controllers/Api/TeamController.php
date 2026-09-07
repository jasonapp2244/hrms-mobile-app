<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a manager needs to know about their own team, and nothing else.
 *
 * Scoped to direct reports rather than the company: a team lead is not HR, and
 * an endpoint that answered for everyone would hand every manager the whole
 * organisation's attendance. The web dashboard is where company-wide figures
 * live, behind permissions this deliberately does not use.
 *
 * The scope and the day's facts both come from shared services — ManagerScope
 * and TeamAttendance — which the manager's web area uses too. They used to be
 * computed here, in forty lines that the web dashboard then had to grow its own
 * copy of; two copies is how a manager and the same manager on their phone end
 * up disagreeing about whether somebody turned up.
 */
class TeamController extends ApiController
{
    public function __construct(
        protected AttendanceService $attendance,
        protected ManagerScope $scope,
        protected TeamAttendance $teamAttendance,
    ) {}

    /**
     * Who is in today, who is not, and why not.
     *
     * The status vocabulary is the same one /attendance/history uses — present,
     * leave, holiday, day_off, weekend, absent — computed by the same method, so
     * a manager and the person they manage can never be looking at two different
     * words for the same day.
     */
    public function attendance(Request $request): JsonResponse
    {
        $manager = $this->employee();

        $data = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $timezone = $this->timezone($manager);
        $today    = now($timezone)->toDateString();
        $date     = $data['date'] ?? $today;

        // A day that has not happened cannot be reported on, and calling anyone
        // absent for it would be a lie they cannot answer.
        if ($date > $today) {
            return $this->fail('invalid_range', 'That day has not happened yet.');
        }

        $team = $this->scope->team($manager);

        // Not an error — a manager whose team is empty gets an empty team, and
        // the app shows "nobody reports to you" rather than a failure.
        if ($team->isEmpty()) {
            return $this->ok([
                'date'     => $date,
                'timezone' => $timezone,
                'summary'  => $this->teamAttendance->summary(collect()),
                'team'     => [],
            ]);
        }

        $rows = $this->teamAttendance->forDate($team, $date, $manager->company_id);

        return $this->ok([
            'date'     => $date,
            'timezone' => $timezone,
            'summary'  => $this->teamAttendance->summary($rows),
            'team'     => $rows->map(fn (array $row) => [
                'employee_id'   => $row['employee']->id,
                'name'          => $row['employee']->full_name,
                'employee_code' => $row['employee']->employee_code,
                'status'        => $row['status'],
                'late'          => $row['late'],
                // Wall clock in the company's zone. The service returns the raw
                // instant so the web side can format it its own way.
                'first_in' => $row['first_in']
                    ? $this->attendance->wallClock($row['first_in'], $timezone)->format('h:i A')
                    : null,
                'last_out' => $row['last_out']
                    ? $this->attendance->wallClock($row['last_out'], $timezone)->format('h:i A')
                    : null,
                'is_clocked_in'  => $row['is_clocked_in'],
                'worked_minutes' => $row['worked_minutes'],
                'shift'          => $this->shiftPayload($row['shift']),
            ])->values(),
        ]);
    }

    /**
     * The team's published roster over a stretch of days (B7.3).
     *
     * Published only, exactly like the employee's own /schedule. A manager
     * seeing draft shifts their team cannot see would tell somebody to come in
     * on a day that is still being planned, and the roster's whole draft /
     * publish distinction exists to prevent that.
     *
     * Returned employee-major rather than date-major — a manager reads down a
     * person to see their week, not across a day to see who is on it, and the
     * day view is what /team/attendance already answers.
     */
    public function roster(Request $request): JsonResponse
    {
        $manager = $this->employee();

        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'days' => 'nullable|integer|between:1,31',
        ]);

        $timezone = $this->timezone($manager);

        $from = Carbon::parse($data['from'] ?? now($timezone)->toDateString());
        $days = (int) ($data['days'] ?? 7);
        $to   = $from->copy()->addDays($days - 1);

        $team = $this->scope->team($manager);

        if ($team->isEmpty()) {
            return $this->ok([
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'timezone' => $timezone,
                'team'     => [],
            ]);
        }

        $rows = $this->teamAttendance->roster(
            $team,
            $from->toDateString(),
            $to->toDateString(),
            $manager->company_id,
        );

        return $this->ok([
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'timezone' => $timezone,
            'team'     => $rows->map(fn (array $row) => [
                'employee_id'   => $row['employee']->id,
                'name'          => $row['employee']->full_name,
                'employee_code' => $row['employee']->employee_code,
                'schedule'      => array_map(fn (array $day) => [
                    'date'    => $day['date'],
                    'status'  => $day['status'],
                    'holiday' => $day['holiday'],
                    'shift'   => $this->shiftPayload($day['shift']),
                    // So the app can show "rostered" apart from "their usual
                    // hours" without a second call.
                    'is_rostered' => $day['is_rostered'],
                ], $row['schedule']),
            ])->values(),
        ]);
    }

    /** One shape for a shift wherever this controller returns one. */
    protected function shiftPayload(?\App\Models\Shift $shift): ?array
    {
        return $shift ? [
            'name'       => $shift->name,
            'start_time' => $shift->start_time,
            'end_time'   => $shift->end_time,
        ] : null;
    }
}
