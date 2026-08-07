<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\LateArrivalsDigest;
use App\Services\AttendanceService;
use Illuminate\Console\Command;

/**
 * Tell HR who was late this morning (A9.3).
 *
 * A digest, sent once per company per day. Per-person alerts through the
 * morning would be a stream nobody reads; the useful artefact is one list.
 *
 * The lateness itself is already decided at punch time — a log carries
 * status 'late' only when it is past the shift start and outside the grace —
 * so this command reads a decision rather than making one. That matters: an
 * alert that disagreed with the attendance log about who was late would be
 * worse than no alert.
 */
class ReportLateArrivals extends Command
{
    protected $signature = 'attendance:report-late
                            {--company= : Limit to one company}
                            {--date= : The work date, defaulting to today in the company timezone}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Send HR a digest of the day\'s late arrivals';

    public function handle(AttendanceService $attendance): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $sent = 0;

        foreach ($companies as $company) {
            $date = $this->option('date') ?: now($company->tz())->toDateString();

            $late = AttendanceLog::with('employee.department')
                ->where('company_id', $company->id)
                ->forDates($date, $date)
                ->where('type', 'in')
                ->where('status', 'late')
                ->orderBy('scanned_at')
                ->get();

            if ($late->isEmpty()) {
                continue;
            }

            // One row per person even if they punched in twice — the digest is
            // about who was late, not how many late punches there were.
            $arrivals = $late->unique('employee_id')->map(function (AttendanceLog $log) use ($attendance) {
                $employee = $log->employee;
                $shift = $employee?->shiftOn($log->work_date->toDateString());

                $minutes = 0;

                if ($shift) {
                    $start = $log->work_date->copy()->setTimeFromTimeString($shift->start_time);
                    $minutes = max(0, (int) $start->diffInMinutes($log->scanned_at, absolute: false));
                }

                return [
                    'name'       => $employee?->full_name ?? 'Unknown',
                    'department' => $employee?->department->name ?? '—',
                    'at'         => $log->scanned_at->format('H:i'),
                    'minutes'    => $minutes,
                ];
            })->values()->all();

            $this->line(sprintf('%s: %d late on %s', $company->name, count($arrivals), $date));

            if ($this->option('dry-run')) {
                continue;
            }

            $recipients = User::where('company_id', $company->id)
                ->where('is_active', true)
                ->get()
                // By permission rather than role name, so a company that renamed
                // its HR role is still told.
                ->filter(fn (User $user) => $user->can('view-reports'));

            foreach ($recipients as $recipient) {
                $recipient->notify(new LateArrivalsDigest($arrivals, $date));
                $sent++;
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run — nothing was sent.'
            : "{$sent} digest(s) sent.");

        return self::SUCCESS;
    }
}
