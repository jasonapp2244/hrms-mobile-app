<?php

namespace App\Console\Commands;

use App\Exports\TableExport;
use App\Mail\ScheduledReportMail;
use App\Models\Company;
use App\Models\ReportSubscription;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Send the reports anybody has subscribed to (A7.12).
 *
 * Runs hourly and decides per company, in that company's own timezone, whether
 * its send hour has arrived — the same shape as the checkout reminder, and for
 * the same reason: with companies in different timezones there is no single
 * hour that is 07:00 everywhere, and a fixed UTC time would land mid-afternoon
 * for half of them.
 *
 * One failed subscription must not take the rest of the run down with it. A
 * report that throws is logged, counted and stepped over, because the most
 * likely cause is one bad recipient address and the other four subscriptions
 * are still owed their mail.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send
                            {--company= : Limit to one company}
                            {--subscription= : Send one subscription, ignoring whether it is due}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Email the reports that are due to their subscribers';

    public function handle(ReportService $reports): int
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->due() as $subscription) {
            $now = now($subscription->company?->tz() ?? config('app.timezone'));
            $period = $subscription->periodFor($now);

            $this->line(sprintf(
                '%s (%s) → %s · %s to %s',
                $subscription->report_label,
                $subscription->frequency,
                implode(', ', $subscription->recipients),
                $period['from'],
                $period['to'],
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $this->deliver($subscription, $reports, $period['from'], $period['to']);

                // Stamped only on success, so a run that failed on a broken
                // mail server is retried on the next pass rather than silently
                // marked as delivered.
                //
                // A --subscription run is somebody pressing "Send Now" to check
                // the thing works. Stamping that would consume the period and
                // suppress the real delivery, which is the opposite of what
                // testing a schedule is meant to achieve.
                if (! $this->option('subscription')) {
                    $subscription->forceFill(['last_sent_at' => now()])->save();
                }

                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("  failed: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run — nothing was sent.'
            : "{$sent} report(s) sent" . ($failed > 0 ? ", {$failed} failed." : '.'));

        // A failure here is worth a non-zero exit: cron mails it, and a report
        // nobody is receiving is exactly the sort of thing that goes unnoticed.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The subscriptions to act on this run.
     *
     * @return \Illuminate\Support\Collection<int, ReportSubscription>
     */
    protected function due()
    {
        $query = ReportSubscription::with(['company', 'office'])
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id));

        // --subscription is the "send it to me now" escape hatch, for testing a
        // new standing order without waiting a month to find out it works.
        if ($id = $this->option('subscription')) {
            return $query->whereKey($id)->get();
        }

        return $query->active()->get()->filter(
            fn (ReportSubscription $s) => $s->isDue(now($s->company?->tz() ?? config('app.timezone'))),
        );
    }

    /** Build the report, render it to a file, and hand it to the mailer. */
    protected function deliver(ReportSubscription $subscription, ReportService $reports, string $from, string $to): void
    {
        $report = $reports->{$subscription->report_type}(
            $subscription->company_id,
            $from,
            $to,
            $subscription->office_id,
        );

        $slug = sprintf('%s_%s_to_%s', $subscription->report_type, $from, $to);

        if ($subscription->format === 'excel') {
            $contents = Excel::raw(
                new TableExport($report['headings'], $report['rows']),
                ExcelFormat::XLSX,
            );
            $filename = $slug . '.xlsx';
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $contents = Pdf::loadView('reports.pdf', $report + [
                'company' => $subscription->company ?? Company::find($subscription->company_id),
                'office'  => $subscription->office,
                'from'    => $from,
                'to'      => $to,
            ])->setPaper('a4', 'landscape')->output();
            $filename = $slug . '.pdf';
            $mime = 'application/pdf';
        }

        Mail::to($subscription->recipients)->send(new ScheduledReportMail(
            subscription: $subscription,
            reportTitle: $report['title'],
            periodFrom: $from,
            periodTo: $to,
            tiles: $report['tiles'],
            filename: $filename,
            fileContents: $contents,
            mimeType: $mime,
        ));
    }
}
