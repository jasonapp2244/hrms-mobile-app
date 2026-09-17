<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\ManagerScope;
use App\Services\TeamAttendance;
use App\Support\Clock;
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
        // The working week and the weekend, from the same service the employee's
        // own schedule and the web calendar ask. A controller that decided for
        // itself which days are a weekend would be a second answer to a
        // question the company has already configured once.
        protected LeaveService $leave,
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
            return $this->fail('invalid_range', __('api.future_day'));
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
                    ? Clock::time($this->attendance->wallClock($row['first_in'], $timezone))
                    : null,
                'last_out' => $row['last_out']
                    ? Clock::time($this->attendance->wallClock($row['last_out'], $timezone))
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

    /**
     * Who on the team is off, and when (B4.6).
     *
     * The month grid the web dashboard has had since A6.7, for the manager who
     * is holding a phone rather than sitting at the desk. Same question, same
     * two statuses, same weekend and holiday rules — computed from
     * `LeaveService::weekendDays` and `Holiday::namedBetween` rather than from a
     * second opinion about which Saturdays this company works.
     *
     * **Behind the same gate as the rest of this controller, and that is the
     * whole reason it is here rather than on the employee's Leave tab.** The
     * directory is explicit that another person's leave is not a
     * colleague-grade fact; a manager, though, already reads every one of these
     * requests in their approval inbox, and `clashesFor` already tells them who
     * else is off over the dates of the one in front of them. This is that same
     * disclosure arranged by day instead of by request, so nothing new is
     * revealed to anybody.
     *
     * **Pending is drawn alongside approved**, which is the point rather than a
     * detail: a month showing only what is already granted is a month a manager
     * can approve a second person onto. Each entry carries its own status so
     * the two never have to look alike.
     *
     * **Direct reports only, and the manager's own leave is not in it.** The
     * team is whatever `ManagerScope` says it is, identically to
     * `/team/attendance` and `/team/roster` — one definition of "my team"
     * across all three, so three screens cannot come to disagree about who is
     * on it.
     *
     * Unlike the attendance board there is **no future cutoff**: leave is
     * booked ahead, so next month is the most useful month this can answer for.
     */
    public function leaveCalendar(Request $request): JsonResponse
    {
        $manager = $this->employee();

        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $timezone = $this->timezone($manager);
        $today    = now($timezone)->toDateString();

        $month = Carbon::parse(($data['month'] ?? substr($today, 0, 7)) . '-01')->startOfMonth();
        $from  = $month->toDateString();
        $to    = $month->copy()->endOfMonth()->toDateString();

        $team = $this->scope->team($manager);

        // Expanded to one entry per date up front, so the grid below is a
        // lookup rather than a scan of every request for every one of
        // thirty-one days — the same shape the web calendar builds.
        $byDate = [];

        if ($team->isNotEmpty()) {
            $people = $team->keyBy('id');

            $requests = LeaveRequest::with('leaveType')
                ->whereIn('employee_id', $people->keys())
                ->whereIn('status', ['approved', 'pending'])
                ->overlapping($from, $to)
                ->orderBy('start_date')
                ->get();

            foreach ($requests as $leave) {
                $person = $people->get($leave->employee_id);

                if (! $person) {
                    continue;
                }

                // Clipped to the month: a fortnight that starts in August has
                // only its September half on September's grid.
                $first = Carbon::parse(max($leave->start_date->toDateString(), $from));
                $last  = Carbon::parse(min($leave->end_date->toDateString(), $to));

                for ($day = $first; $day->lte($last); $day->addDay()) {
                    $byDate[$day->toDateString()][] = [
                        'employee_id'   => $person->id,
                        'name'          => $person->full_name,
                        'employee_code' => $person->employee_code,
                        'leave_type'    => $leave->leaveType?->name,
                        'status'        => $leave->status,
                        'is_half_day'   => (bool) $leave->is_half_day,
                        'half_day_period' => $leave->half_day_period,
                        // The whole stretch, not the day this entry sits on, so
                        // a tap can say "Mon–Fri" without a second request.
                        'start_date' => $leave->start_date->toDateString(),
                        'end_date'   => $leave->end_date->toDateString(),
                    ];
                }
            }
        }

        $weekend  = $this->leave->weekendDays($manager->company);
        $holidays = $manager->company_id
            ? Holiday::namedBetween($manager->company_id, $from, $to)
            : [];

        $days = [];

        for ($day = $month->copy(); $day->toDateString() <= $to; $day->addDay()) {
            $date = $day->toDateString();

            $days[] = [
                'date'    => $date,
                'weekend' => in_array($day->dayOfWeek, $weekend, true),
                'holiday' => $holidays[$date] ?? null,
                'people'  => $byDate[$date] ?? [],
            ];
        }

        return $this->ok([
            'month'    => $month->format('Y-m'),
            'from'     => $from,
            'to'       => $to,
            'timezone' => $timezone,
            // The company's today, so the grid can mark it without asking the
            // handset — which is in whatever zone its owner is standing in.
            'today'     => $today,
            'team_size' => $team->count(),
            'days'      => $days,
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
