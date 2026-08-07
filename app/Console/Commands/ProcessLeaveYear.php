<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\LeaveService;
use Illuminate\Console\Command;

/**
 * Monthly accrual and the year-end roll (A6.4, A6.9).
 *
 * One command for both because they are the same job seen twice: the balance
 * for a year has to be brought up to date, and on the first of January the year
 * that needs bringing up to date is a new one. Splitting them would mean two
 * schedules that must not overlap and two chances to run only half of it.
 *
 * Runs daily rather than monthly. Both operations are idempotent — accrual only
 * ever raises a figure to what has been earned, and the carry-forward creates a
 * row or leaves it alone — so running it every day costs a query and means a
 * server that was down on the 1st catches up on the 2nd instead of leaving
 * everybody without a balance for a month.
 */
class ProcessLeaveYear extends Command
{
    protected $signature = 'leave:process
                            {--company= : Limit to one company}
                            {--year= : The year to work on, defaulting to the current one}
                            {--carry-forward : Also roll last year into this one}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Accrue monthly leave entitlement and roll balances into the new year';

    public function handle(LeaveService $leave): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $year = (int) ($this->option('year') ?: date('Y'));

        // On the first few days of January, roll last year in as well — without
        // being asked. That is the one date this has to happen and the one date
        // nobody is watching. The window is days rather than a single date so a
        // server down over New Year still catches it, and the roll is idempotent
        // so the repeat costs nothing.
        $carry = $this->option('carry-forward')
            || (now()->month === 1 && now()->day <= 7);

        $accrued = 0;
        $created = 0;

        foreach ($companies as $company) {
            if ($this->option('dry-run')) {
                $this->line("{$company->name}: would accrue and " . ($carry ? 'roll' : 'not roll') . ' balances.');
                continue;
            }

            if ($carry) {
                $rolled = $leave->carryForward($company->id, $year - 1);
                $created += $rolled;

                if ($rolled > 0) {
                    $this->line("{$company->name}: {$rolled} balance(s) opened for {$year}.");
                }
            }

            $moved = $leave->accrue($company->id, $year);
            $accrued += $moved;

            if ($moved > 0) {
                $this->line("{$company->name}: {$moved} balance(s) accrued.");
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run — nothing was changed.'
            : "{$accrued} balance(s) accrued, {$created} opened for the new year.");

        return self::SUCCESS;
    }
}
