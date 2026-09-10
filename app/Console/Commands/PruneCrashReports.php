<?php

namespace App\Console\Commands;

use App\Models\CrashReport;
use Illuminate\Console\Command;

/**
 * Drops crash reports past their keeping (B6.5).
 *
 * `crash_reports` is fed by an **unauthenticated** endpoint, which makes it the
 * one table in the system that can grow without anybody signing in to grow it.
 * The endpoint is rate-limited and every field is capped, so this is not a
 * defence against abuse — it is the ordinary housekeeping that stops a server
 * nobody watches accumulating a year of stack traces for a bug fixed in March.
 *
 * Unlike the audit trails it sits beside, a crash report is diagnosis and not
 * evidence: nothing is calculated from one, and nobody is accountable to one.
 * That is why it is safe to delete on a timer and they are not.
 */
class PruneCrashReports extends Command
{
    protected $signature = 'crashes:prune {--days= : Override the retention window}';

    protected $description = 'Delete crash reports older than the retention window';

    public function handle(): int
    {
        // `??`, not `?:`. `--days=0` is the way to say "keep everything", and
        // `?:` treats "0" as absent and quietly falls back to the configured
        // window — deleting exactly what the flag was asking to spare.
        $option = $this->option('days');

        $days = (int) ($option ?? config('mobile.crash_retention_days'));

        if ($days < 1) {
            $this->components->warn('Retention is off — nothing was pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // On created_at rather than occurred_at. The window is about how long
        // this server keeps a thing it was told, and a handset with a wrong
        // clock must not be able to age its own report out on arrival.
        $deleted = CrashReport::where('created_at', '<', $cutoff)->delete();

        $this->components->info(
            $deleted === 0
                ? "No crash reports older than {$days} days."
                : "Pruned {$deleted} crash report(s) older than {$days} days.",
        );

        return self::SUCCESS;
    }
}
