<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Nudge anybody still clocked in after their shift has ended.
 *
 * Runs every quarter of an hour rather than once at a fixed time, because a
 * company on rotating shifts has no single "end of day" — the night shift
 * finishes when the morning one starts.
 */
class RemindMissingCheckout extends Command
{
    protected $signature = 'attendance:remind-checkout
                            {--company= : Limit to one company}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Remind employees who are still clocked in after their shift ended';

    public function handle(AttendanceService $attendance): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $reminded = 0;

        foreach ($companies as $company) {
            $now = now($company->tz());
            $grace = (int) $company->policy('checkout_reminder_after_minutes');

            // Yesterday as well as today: a night shift that began yesterday is
            // still yesterday's work date at two in the morning.
            foreach ([$now->copy()->subDay()->toDateString(), $now->toDateString()] as $workDate) {
                foreach ($attendance->openPunches($company->id, $workDate) as $punch) {
                    $employee = $punch->employee;
                    $end = $employee ? $attendance->shiftEndFor($employee, $workDate) : null;

                    // Nobody rostered means no shift end, so there is nothing to
                    // be late leaving from.
                    if (! $end || $now->lessThan($end->copy()->addMinutes($grace))) {
                        continue;
                    }

                    // Once each. A reminder repeated every quarter of an hour
                    // until somebody acts is how people learn to mute an app.
                    if ($this->alreadyReminded($punch, $workDate)) {
                        continue;
                    }

                    $user = $employee?->user;

                    if (! $user) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s — clocked in %s, shift ended %s',
                        $employee->full_name,
                        $punch->scanned_at->format('H:i'),
                        $end->format('H:i'),
                    ));

                    if (! $this->option('dry-run')) {
                        $user->notify(new \App\Notifications\MissingCheckoutReminder($punch, $workDate));
                    }

                    $reminded++;
                }
            }
        }

        $this->info($this->option('dry-run')
            ? "{$reminded} reminder(s) would be sent."
            : "{$reminded} reminder(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Has this person already been told about this day?
     *
     * Read off their notifications rather than a column on the punch: the fact
     * being recorded is "we told them", and that already has a home.
     */
    protected function alreadyReminded(AttendanceLog $punch, string $workDate): bool
    {
        return $punch->employee?->user?->notifications()
            ->where('type', \App\Notifications\MissingCheckoutReminder::class)
            ->get()
            ->contains(fn ($note) => ($note->data['work_date'] ?? null) === $workDate) ?? false;
    }
}
