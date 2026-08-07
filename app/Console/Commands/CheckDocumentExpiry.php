<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\DocumentExpiring;
use Illuminate\Console\Command;

/**
 * Chase documents that are about to lapse (A3.8).
 *
 * The reason the vault exists. A visa or a right-to-work document that expired
 * unnoticed is a compliance failure, and the only reason it goes unnoticed is
 * that nobody is watching a folder.
 *
 * Told once per document, not once per night: `expiry_notified_at` is stamped
 * on send and only cleared by the row being edited. A nightly mail about the
 * same passport for thirty consecutive days is a mail rule, not a warning.
 */
class CheckDocumentExpiry extends Command
{
    protected $signature = 'documents:check-expiry
                            {--company= : Limit to one company}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Warn HR about employee documents that are expiring or have expired';

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $sent = 0;

        foreach ($companies as $company) {
            $documents = EmployeeDocument::with('employee')
                ->needingExpiryWarning($company->id)
                ->get();

            if ($documents->isEmpty()) {
                continue;
            }

            // Everybody who can act on it. Read from the permission rather than
            // the role name so a custom role with manage-employees is included —
            // otherwise the company that renamed "hr" to "people-ops" silently
            // stops being told.
            $recipients = User::where('company_id', $company->id)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user) => $user->can('manage-employees'));

            if ($recipients->isEmpty()) {
                $this->warn("{$company->name}: {$documents->count()} document(s) need chasing, but nobody holds manage-employees.");
                continue;
            }

            foreach ($documents as $document) {
                $this->line(sprintf(
                    '%s — %s (%s)',
                    $document->employee?->full_name ?? 'unknown employee',
                    $document->title,
                    $document->expires_on?->toDateString() ?? 'no date',
                ));

                if ($this->option('dry-run')) {
                    continue;
                }

                foreach ($recipients as $recipient) {
                    $recipient->notify(new DocumentExpiring($document));
                }

                // Stamped with the timestamps held still, so `updated_at` does
                // not move past `expiry_notified_at` and immediately re-qualify
                // the row for another warning on the next run.
                $document->timestamps = false;
                $document->forceFill(['expiry_notified_at' => now()])->save();
                $document->timestamps = true;

                $sent++;
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run — nothing was sent.'
            : "{$sent} document warning(s) sent.");

        return self::SUCCESS;
    }
}
