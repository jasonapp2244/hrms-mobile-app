<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ShiftAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * One day of a team's attendance, computed once.
 *
 * Both the manager's web dashboard and the app's `/team/attendance` answer the
 * same question — who was in, who was late, who is still on the clock — and
 * they used to answer it with two copies of the same forty lines. Two copies is
 * how a manager and the same manager on their phone end up disagreeing about
 * whether somebody was absent.
 *
 * Returned as models and Carbon instants rather than formatted strings: the API
 * needs wall-clock text in the company's zone, Blade needs the model to link
 * to, and a service that picked one would force the other to unpick it.
 */
class TeamAttendance
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    /**
     * A row per team member for one date.
     *
     * The calendar facts — which days the company works, which are holidays,
     * who is on leave — are fetched once for the whole team rather than per
     * person. On a team of thirty that is the difference between four queries
     * and ninety.
     *
     * @param  EloquentCollection<int, Employee>  $team
     * @return Collection<int, array>
     */
    public function forDate(EloquentCollection $team, string $date, ?int $companyId = null): Collection
    {
        if ($team->isEmpty()) {
            return collect();
        }

        $companyId ??= $team->first()->company_id;
        $company = $team->first()->company;
        $employeeIds = $team->pluck('id');

        $logs = AttendanceLog::whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $working  = array_flip($this->leave->workingDatesBetween($company, $date, $date));
        $holidays = $companyId ? Holiday::namedBetween($companyId, $date, $date) : [];
        $leaveByEmployee = $this->leave->leaveDatesByEmployee($companyId, $date, $date);

        $daysOff = ShiftAssignment::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $date)
            ->where('is_day_off', true)
            ->pluck('employee_id')
            ->flip();

        $isWorkingDay = isset($working[$date]);
        $holiday      = $holidays[$date] ?? null;

        return $team->map(function (Employee $employee) use (
            $logs, $leaveByEmployee, $daysOff, $isWorkingDay, $holiday, $date
        ) {
            $dayLogs = $logs->get($employee->id, collect());
            $firstIn = $dayLogs->firstWhere('type', 'in');
            $lastOut = $dayLogs->last(fn ($log) => $log->type === 'out');
            $last    = $dayLogs->last();

            $onLeave = in_array($date, $leaveByEmployee[$employee->id] ?? [], true);

            return [
                'employee' => $employee,
                'status'   => $this->attendance->dayStatus(
                    $dayLogs->isNotEmpty(),
                    $onLeave,
                    $holiday !== null,
                    $daysOff->has($employee->id),
                    $isWorkingDay,
                ),
                'late'     => $firstIn?->status === 'late',
                'first_in' => $firstIn?->scanned_at,
                'last_out' => $lastOut?->scanned_at,
                // Still on the clock right now, which is the question a manager
                // walking the floor is actually asking. A break counts as in:
                // they are at work, just not at the mop.
                'is_clocked_in'  => (bool) ($last && in_array($last->type, ['in', 'break_start', 'break_end'], true)),
                'worked_minutes' => $this->attendance->workedMinutes($dayLogs),
                // shiftOn rather than the raw override: roster-aware, so this is
                // the shift the roster actually put them on that day and not the
                // one they usually work.
                'shift' => $employee->shiftOn($date),
            ];
        })->values();
    }

    /**
     * The counts a manager reads before the list.
     *
     * `in_now` is separate from `present` on purpose: somebody who worked this
     * morning and went home is present for the day but not on the floor, and
     * conflating the two answers the wrong question.
     *
     * @param  Collection<int, array>  $rows
     */
    public function summary(Collection $rows): array
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

    /**
     * The team's published roster over a stretch of days.
     *
     * Published only. A manager seeing draft shifts their staff cannot see
     * would tell somebody to come in on a day that is still being planned, and
     * the roster's whole draft/publish distinction exists to stop exactly that.
     *
     * Employee-major rather than date-major: a manager reads down a person to
     * see their week. The day-across view is what `forDate` already answers.
     *
     * @param  EloquentCollection<int, Employee>  $team
     * @return Collection<int, array>
     */
    public function roster(EloquentCollection $team, string $from, string $to, ?int $companyId = null): Collection
    {
        if ($team->isEmpty()) {
            return collect();
        }

        $companyId ??= $team->first()->company_id;
        $company = $team->first()->company;

        $assignments = ShiftAssignment::with('shift')
            ->whereIn('employee_id', $team->pluck('id'))
            // The model's own scope, not whereBetween: `date` is a date cast and
            // carries a time component on any engine without a real DATE type,
            // so a plain string range silently misses rows.
            ->between($from, $to)
            ->whereNotNull('published_at')
            ->get()
            ->groupBy('employee_id');

        $holidays = $companyId ? Holiday::namedBetween($companyId, $from, $to) : [];
        $working  = array_flip($this->leave->workingDatesBetween($company, $from, $to));
        $leaveByEmployee = $this->leave->leaveDatesByEmployee($companyId, $from, $to);

        $start = \Carbon\Carbon::parse($from);
        $end   = \Carbon\Carbon::parse($to);

        return $team->map(function (Employee $employee) use (
            $assignments, $holidays, $working, $leaveByEmployee, $start, $end
        ) {
            $planned = $assignments->get($employee->id, collect())
                ->keyBy(fn (ShiftAssignment $a) => $a->date->toDateString());

            $onLeave = array_flip($leaveByEmployee[$employee->id] ?? []);

            $schedule = [];

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
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

                // A published day shows the shift it was published with. A day
                // with nothing published falls back to the employee's *standing*
                // shift — their usual hours — and deliberately not to
                // shiftOn(), which consults the roster table directly and is
                // blind to published_at. Going through it here leaked the name
                // of an unpublished draft onto a screen whose entire contract is
                // "published only", which is the one thing the publish step
                // exists to prevent.
                $shift = match (true) {
                    $status !== 'working' => null,
                    $assignment !== null  => $assignment->shift,
                    default               => $employee->shift,
                };

                $schedule[] = [
                    'date'        => $date,
                    'status'      => $status,
                    'holiday'     => $holidays[$date] ?? null,
                    'shift'       => $shift,
                    'is_rostered' => $assignment !== null,
                ];
            }

            return ['employee' => $employee, 'schedule' => $schedule];
        })->values();
    }

    /**
     * Days somebody clocked in and never clocked out, before today.
     *
     * Today is excluded deliberately — a cleaner three hours into a shift has
     * an open punch and nothing is wrong. It becomes a problem only once the
     * day is over, which is exactly when the auto-close policy (A4.18) has not
     * been switched on to catch it.
     *
     * @param  EloquentCollection<int, Employee>  $team
     * @return Collection<int, AttendanceLog>
     */
    public function missingCheckouts(EloquentCollection $team, string $from, string $beforeDate): Collection
    {
        if ($team->isEmpty()) {
            return collect();
        }

        return AttendanceLog::with('employee')
            ->whereIn('employee_id', $team->pluck('id'))
            // The model scope, never whereBetween: `work_date` is a date cast
            // and carries a time component on any engine without a real DATE
            // type, so a plain string range drops the last day silently.
            ->forDates($from, $beforeDate)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy(fn (AttendanceLog $log) => $log->employee_id . '|' . $log->work_date->toDateString())
            ->map(fn ($logs) => $logs->last())
            // Only the last punch of a day matters: in/out/in is still open,
            // in/out is not, however many pairs came before.
            ->filter(fn (AttendanceLog $last) => $last->type !== 'out'
                && $last->work_date->toDateString() < $beforeDate)
            ->sortByDesc(fn (AttendanceLog $log) => $log->work_date->toDateString())
            ->values();
    }
}
