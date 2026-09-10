<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Notifications\ShiftStartingReminder;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Illuminate\Console\Command;

/**
 * Remind people their shift is about to start (B5.1).
 *
 * The mirror of {@see RemindMissingCheckout}, and it has the opposite problem:
 * that one fires after a moment has passed and can afford to be late, this one
 * fires inside a window that closes. The window is
 * `[start - lead, start)` — never after the start, because a reminder to clock
 * in that arrives once the shift has begun is an accusation, not a reminder,
 * and the late-arrival digest (A9.3) already covers that ground.
 *
 * **The lead must be at least as long as the gap between scheduler runs.** With
 * a ten-minute lead and a quarter-hourly scheduler, an 09:00 shift's window
 * (08:50–09:00) can fall between the 08:45 and 09:00 runs and nobody is ever
 * reminded — silently, for every employee, forever. `routes/console.php` runs
 * this every five minutes and `PolicyController` refuses a lead between 1 and 4
 * for that reason. Zero is allowed and means off.
 */
class RemindShiftStart extends Command
{
    protected $signature = 'attendance:remind-checkin
                            {--company= : Limit to one company}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Remind rostered employees shortly before their shift starts';

    public function __construct(protected LeaveService $leave)
    {
        parent::__construct();
    }

    public function handle(AttendanceService $attendance): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $reminded = 0;

        foreach ($companies as $company) {
            $lead = (int) $company->policy('checkin_reminder_before_minutes');

            // Off for this company. Nothing to schedule, nothing to log.
            if ($lead <= 0) {
                continue;
            }

            $now = now($company->tz());

            // Tomorrow as well as today: a shift starting at 00:15 opens its
            // window before midnight, on the previous calendar day.
            $today    = $now->toDateString();
            $tomorrow = $now->copy()->addDay()->toDateString();

            // The calendar facts for both days in one pass rather than a query
            // per employee per day.
            $working    = array_flip($this->leave->workingDatesBetween($company, $today, $tomorrow));
            $leaveDates = $this->leave->leaveDatesByEmployee($company->id, $today, $tomorrow);

            // Neither day is worked — a bank-holiday weekend. Skip the whole
            // company rather than loading its staff to reject each of them.
            if ($working === []) {
                continue;
            }

            $employees = Employee::query()
                // `shift` is an accessor, not a relation — the standing shift is
                // an override on the employee falling back to the department's,
                // so both sides of that have to be here.
                ->with(['user', 'company', 'shiftOverride', 'department.shift'])
                // Only the two days in play. `shiftOn()` reads the loaded
                // collection when there is one, so a wider window would make it
                // pick the wrong day's roster.
                ->with(['shiftAssignments' => fn ($q) => $q
                    ->with('shift')
                    ->whereDate('date', '>=', $today)
                    ->whereDate('date', '<=', $tomorrow)])
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->whereNotNull('user_id')
                ->get();

            foreach ($employees as $employee) {
                foreach ([$today, $tomorrow] as $workDate) {
                    if ($this->send($attendance, $employee, $workDate, $now, $lead, $working, $leaveDates)) {
                        $reminded++;
                    }
                }
            }
        }

        $this->info($this->option('dry-run')
            ? "{$reminded} reminder(s) would be sent."
            : "{$reminded} reminder(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Decide on one employee's one day, and send if it is due.
     *
     * @param  array<string, int>  $working  Working dates, flipped to a lookup.
     * @param  array<int, array<int, string>>  $leaveDates  Approved leave dates per employee id.
     */
    protected function send(
        AttendanceService $attendance,
        Employee $employee,
        string $workDate,
        \Carbon\CarbonInterface $now,
        int $lead,
        array $working,
        array $leaveDates,
    ): bool {
        // A weekend or a company holiday. Somebody rostered onto one anyway
        // gets no reminder: the roster is the exception, the calendar is the
        // rule, and guessing wrong here wakes people on their day off.
        if (! isset($working[$workDate])) {
            return false;
        }

        if (in_array($workDate, $leaveDates[$employee->id] ?? [], true)) {
            return false;
        }

        // Null covers both "no shift at all" and an explicit day off on the
        // roster — `shiftOn()` already folds the two together.
        $start = $attendance->shiftStartFor($employee, $workDate);

        if (! $start) {
            return false;
        }

        // Before the window opens, or after the shift has already begun.
        if ($now->lessThan($start->copy()->subMinutes($lead)) || $now->greaterThanOrEqualTo($start)) {
            return false;
        }

        // Already at work. The commonest case on any run, and the reason the
        // check is a punch lookup rather than anything cleverer: somebody who
        // clocked in early does not need telling to clock in.
        if ($this->alreadyClockedIn($employee, $workDate)) {
            return false;
        }

        // Once each, however often the scheduler runs — the same reason the
        // checkout reminder reads its own history.
        if ($this->alreadyReminded($employee, $workDate)) {
            return false;
        }

        $this->line(sprintf(
            '%s — shift starts %s on %s',
            $employee->full_name,
            $start->format('H:i'),
            $workDate,
        ));

        if (! $this->option('dry-run')) {
            $employee->user->notify(new ShiftStartingReminder($workDate, $start));
        }

        return true;
    }

    /** Any clock-in on this work date, whatever happened afterwards. */
    protected function alreadyClockedIn(Employee $employee, string $workDate): bool
    {
        return AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->where('type', 'in')
            ->exists();
    }

    /**
     * Has this person already been told about this day?
     *
     * Read off their notifications rather than a column somewhere: the fact
     * being recorded is "we told them", and that already has a home.
     */
    protected function alreadyReminded(Employee $employee, string $workDate): bool
    {
        return $employee->user->notifications()
            ->where('type', ShiftStartingReminder::class)
            ->get()
            ->contains(fn ($note) => ($note->data['work_date'] ?? null) === $workDate);
    }
}
