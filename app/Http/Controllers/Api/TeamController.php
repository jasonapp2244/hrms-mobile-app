<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\AttendanceService;
use App\Services\LeaveService;
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
 */
class TeamController extends ApiController
{
    public function __construct(
        protected AttendanceService $attendance,
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
            return $this->fail('invalid_range', 'That day has not happened yet.');
        }

        $team = Employee::query()
            ->where('manager_id', $manager->id)
            ->where('company_id', $manager->company_id)
            ->active()
            ->orderBy('first_name')
            ->get();

        // Not an error — a manager whose team is empty gets an empty team, and
        // the app shows "nobody reports to you" rather than a failure.
        if ($team->isEmpty()) {
            return $this->ok([
                'date'     => $date,
                'timezone' => $timezone,
                'summary'  => $this->summary(collect()),
                'team'     => [],
            ]);
        }

        $employeeIds = $team->pluck('id');

        // The calendar facts once for the whole team rather than per person.
        $logs = AttendanceLog::whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $working  = array_flip($this->leave->workingDatesBetween($manager->company, $date, $date));
        $holidays = $manager->company
            ? Holiday::namedBetween($manager->company_id, $date, $date)
            : [];
        $leaveByEmployee = $this->leave->leaveDatesByEmployee($manager->company_id, $date, $date);

        $daysOff = \App\Models\ShiftAssignment::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $date)
            ->where('is_day_off', true)
            ->pluck('employee_id')
            ->flip();

        $isWorkingDay = isset($working[$date]);
        $holiday      = $holidays[$date] ?? null;

        $rows = $team->map(function (Employee $employee) use (
            $logs, $leaveByEmployee, $daysOff, $isWorkingDay, $holiday, $date, $timezone
        ) {
            $dayLogs = $logs->get($employee->id, collect());
            $firstIn = $dayLogs->firstWhere('type', 'in');
            $lastOut = $dayLogs->last(fn ($log) => $log->type === 'out');
            $last    = $dayLogs->last();

            $onLeave = in_array($date, $leaveByEmployee[$employee->id] ?? [], true);

            return [
                'employee_id'   => $employee->id,
                'name'          => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'status'        => $this->attendance->dayStatus(
                    $dayLogs->isNotEmpty(),
                    $onLeave,
                    $holiday !== null,
                    $daysOff->has($employee->id),
                    $isWorkingDay,
                ),
                'late'     => $firstIn?->status === 'late',
                'first_in' => $firstIn
                    ? $this->attendance->wallClock($firstIn->scanned_at, $timezone)->format('h:i A')
                    : null,
                'last_out' => $lastOut
                    ? $this->attendance->wallClock($lastOut->scanned_at, $timezone)->format('h:i A')
                    : null,
                // Still on the clock right now, which is the question a manager
                // walking the floor is actually asking.
                'is_clocked_in'  => (bool) ($last && $last->type === 'in'),
                'worked_minutes' => $this->attendance->workedMinutes($dayLogs),
                // shiftOn rather than the raw override: it is roster-aware, so
                // a manager sees the shift the roster actually put somebody on
                // that day, not the one they usually work.
                'shift' => ($shift = $employee->shiftOn($date)) ? [
                    'name'       => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                ] : null,
            ];
        });

        return $this->ok([
            'date'     => $date,
            'timezone' => $timezone,
            'summary'  => $this->summary($rows),
            'team'     => $rows->values(),
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

        $team = Employee::query()
            ->where('manager_id', $manager->id)
            ->where('company_id', $manager->company_id)
            ->active()
            ->orderBy('first_name')
            ->get();

        if ($team->isEmpty()) {
            return $this->ok([
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'timezone' => $timezone,
                'team' => [],
            ]);
        }

        $assignments = \App\Models\ShiftAssignment::with('shift')
            ->whereIn('employee_id', $team->pluck('id'))
            // The model's own scope, not whereBetween: `date` is a date cast and
            // carries a time component on any engine without a real DATE type,
            // so a plain string range silently misses rows.
            ->between($from->toDateString(), $to->toDateString())
            ->whereNotNull('published_at')
            ->get()
            ->groupBy('employee_id');

        $holidays = $manager->company
            ? Holiday::namedBetween($manager->company_id, $from->toDateString(), $to->toDateString())
            : [];

        $working = array_flip($this->leave->workingDatesBetween(
            $manager->company, $from->toDateString(), $to->toDateString(),
        ));

        $leaveByEmployee = $this->leave->leaveDatesByEmployee(
            $manager->company_id, $from->toDateString(), $to->toDateString(),
        );

        $rows = $team->map(function (Employee $employee) use (
            $assignments, $holidays, $working, $leaveByEmployee, $from, $to
        ) {
            $planned = $assignments->get($employee->id, collect())
                ->keyBy(fn ($a) => $a->date->toDateString());

            $onLeave = array_flip($leaveByEmployee[$employee->id] ?? []);

            $schedule = [];

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $assignment = $planned->get($date);

                // Leave outranks the roster: somebody rostered on a day they
                // later booked off is not working it, and showing the shift
                // would have a manager expecting them.
                $status = match (true) {
                    isset($onLeave[$date])          => 'leave',
                    isset($holidays[$date])         => 'holiday',
                    (bool) $assignment?->is_day_off => 'day_off',
                    $assignment !== null            => 'working',
                    ! isset($working[$date])        => 'weekend',
                    // Nothing planned on a working day falls back to their
                    // standing shift, which is what actually happens.
                    default                         => 'working',
                };

                $shift = $status === 'working' ? $employee->shiftOn($date) : null;

                $schedule[] = [
                    'date'    => $date,
                    'status'  => $status,
                    'holiday' => $holidays[$date] ?? null,
                    'shift'   => $shift ? [
                        'name'       => $shift->name,
                        'start_time' => $shift->start_time,
                        'end_time'   => $shift->end_time,
                    ] : null,
                    // So the app can show "rostered" apart from "their usual
                    // hours" without a second call.
                    'is_rostered' => $assignment !== null,
                ];
            }

            return [
                'employee_id'   => $employee->id,
                'name'          => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'schedule'      => $schedule,
            ];
        });

        return $this->ok([
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'timezone' => $timezone,
            'team'     => $rows->values(),
        ]);
    }

    /**
     * The counts a manager reads before the list.
     *
     * `in_now` is separate from `present` on purpose: somebody who worked this
     * morning and went home is present for the day but not on the floor, and
     * conflating the two would answer the wrong question.
     */
    protected function summary(\Illuminate\Support\Collection $rows): array
    {
        return [
            'total'    => $rows->count(),
            'present'  => $rows->where('status', 'present')->count(),
            'in_now'   => $rows->where('is_clocked_in', true)->count(),
            'late'     => $rows->where('late', true)->count(),
            'on_leave' => $rows->where('status', 'leave')->count(),
            'absent'   => $rows->where('status', 'absent')->count(),
            'off'      => $rows->whereIn('status', ['day_off', 'weekend', 'holiday'])->count(),
        ];
    }
}
