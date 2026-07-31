<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Console\Command;

/**
 * Close out days nobody clocked out of, and refresh the month's scores.
 *
 * Absence is deliberately not written anywhere. It is derived — a working day
 * with no punch and no leave — and a stored "absent" row would be a second
 * answer to the same question, wrong the moment somebody's leave is approved
 * after the fact. The scores this command refreshes are a cache of that
 * derivation, not a replacement for it.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'attendance:close-day
                            {--company= : Limit to one company}
                            {--date= : The work date to close (default: yesterday and today)}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Auto-close missing clock-outs and refresh monthly attendance scores';

    public function handle(AttendanceService $attendance): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $closed = 0;

        foreach ($companies as $company) {
            $now = now($company->tz());
            $grace = (int) $company->policy('auto_close_after_minutes');

            $dates = $this->option('date')
                ? [$this->option('date')]
                // Yesterday too: a night shift beginning yesterday evening is
                // still open at midnight and closes in the small hours.
                : [$now->copy()->subDay()->toDateString(), $now->toDateString()];

            foreach ($dates as $workDate) {
                foreach ($attendance->openPunches($company->id, $workDate) as $punch) {
                    $employee = $punch->employee;
                    $end = $employee ? $attendance->shiftEndFor($employee, $workDate) : null;

                    // Still within the grace period: overtime is normal, and
                    // closing a day somebody is still working would understate
                    // the hours they actually put in.
                    if (! $end || $now->lessThan($end->copy()->addMinutes($grace))) {
                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line(sprintf('%s — would close %s at %s',
                            $employee->full_name, $workDate, $end->format('H:i')));
                        $closed++;
                        continue;
                    }

                    if ($attendance->autoClose($punch)) {
                        $this->line(sprintf('%s — closed %s at %s',
                            $employee->full_name, $workDate, $end->format('H:i')));
                        $closed++;
                    }
                }
            }

            if (! $this->option('dry-run')) {
                $this->refreshScores($attendance, $company, $now->format('Y-m'));
            }
        }

        $this->info($this->option('dry-run')
            ? "{$closed} day(s) would be closed."
            : "{$closed} day(s) closed.");

        return self::SUCCESS;
    }

    /**
     * Recompute this month's scores for everyone still employed.
     *
     * Cheap enough to redo wholesale each night, and doing so means a score is
     * never stale after leave is approved retrospectively or a punch corrected.
     */
    protected function refreshScores(AttendanceService $attendance, Company $company, string $period): void
    {
        Employee::where('company_id', $company->id)
            ->active()
            ->chunkById(100, function ($employees) use ($attendance, $period) {
                foreach ($employees as $employee) {
                    $attendance->computeMonthlyScore($employee, $period);
                }
            });
    }
}
