<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remove the rows DemoDataSeeder and AttendanceSeeder invented.
 *
 * Anything seeded is identified by the exact values those two seeders write —
 * the five `EMP-000n` codes, the seven `@emp.test` / `@acme.test` addresses,
 * and `source = 'kiosk'` on attendance. Deliberately not by pattern matching:
 * a rule like "delete test-looking emails" would eventually eat somebody real.
 * `kiosk` is safe as a marker because nothing in the application writes it —
 * the kiosk screen was removed, and the code paths that survive write 'auto',
 * 'button' or 'mobile'.
 *
 * The part worth reading the output for is the cascade. Attendance, leave and
 * balances are all `on delete cascade` from `employees`, so deleting a demo
 * employee silently takes their real rows too — and on an install that has been
 * used for testing, the real punches are usually against a demo account, which
 * is exactly the case this would quietly destroy. So --dry-run counts those
 * separately and names them, and the confirmation prompt repeats the number.
 *
 * Run --dry-run first. It is the whole point of the command.
 */
class PurgeDemoData extends Command
{
    protected $signature = 'emp:purge-demo
                            {--dry-run : Show what would be deleted and change nothing}
                            {--keep-employees=* : Employee codes to spare, e.g. --keep-employees=EMP-0001}
                            {--force : Allow this to run with APP_ENV=production}';

    protected $description = 'Delete the demo company, users, employees and attendance created by the seeders';

    /** Exactly the accounts DemoDataSeeder creates. */
    private const DEMO_EMAILS = [
        'admin@emp.test',
        'hr@emp.test',
        'james.smith@acme.test',
        'emily.johnson@acme.test',
        'michael.brown@acme.test',
        'jessica.davis@acme.test',
        'david.wilson@acme.test',
    ];

    /** Exactly the employee codes DemoDataSeeder creates. */
    private const DEMO_CODES = [
        'EMP-0001', 'EMP-0002', 'EMP-0003', 'EMP-0004', 'EMP-0005',
    ];

    /** Written only by AttendanceSeeder; no live code path produces it. */
    private const SEEDED_SOURCE = 'kiosk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('  Refusing to run on production without --force.');
            $this->line('  <fg=gray>A correctly deployed install was never seeded, so there should be</>');
            $this->line('  <fg=gray>nothing here to remove. Run with --dry-run first and read it.</>');

            return self::FAILURE;
        }

        $keep = array_map('strtoupper', (array) $this->option('keep-employees'));

        $employees = Employee::whereIn('employee_code', array_diff(self::DEMO_CODES, $keep))->get();

        // A spared employee keeps their login. Deleting it would leave an
        // employee record nobody can sign in as — and since EMP-0001 is the
        // manager the seeder puts everyone else under, that means a team whose
        // leave nobody can approve.
        $sparedUserIds = Employee::whereIn('employee_code', $keep)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        $users = User::whereIn('email', self::DEMO_EMAILS)
            ->whereNotIn('id', $sparedUserIds ?: [0])
            ->get();

        // Companies the seeder forked. Only ever the empty ones: the company
        // holding the real staff must survive even if it is still called Acme.
        $companies = Company::whereIn('name', ['Acme Corporation', 'Acme'])
            ->get()
            ->filter(fn (Company $c) => ! $c->employees()->exists()
                && ! User::where('company_id', $c->id)->exists());

        $employeeIds = $employees->pluck('id')->all();

        $seededLogs = AttendanceLog::where('source', self::SEEDED_SOURCE)->count();

        // The number that matters. Rows nobody asked to delete, which the
        // foreign keys will take anyway because they hang off a demo employee.
        $collateralLogs = $employeeIds
            ? AttendanceLog::whereIn('employee_id', $employeeIds)
                ->where('source', '!=', self::SEEDED_SOURCE)
                ->get(['id', 'employee_id', 'type', 'source', 'scanned_at'])
            : collect();

        $collateralLeave = $employeeIds
            ? DB::table('leave_requests')->whereIn('employee_id', $employeeIds)->count()
            : 0;

        // Staff who report to somebody about to be deleted. The link is SET
        // NULL, so they survive — but they lose their approver, and nobody
        // approves their leave until a new one is set.
        $orphanedReports = $employeeIds
            ? Employee::whereIn('manager_id', $employeeIds)
                ->whereNotIn('id', $employeeIds)
                ->get(['id', 'employee_code', 'first_name', 'last_name'])
            : collect();

        $this->render($employees, $users, $companies, $seededLogs, $collateralLogs, $collateralLeave, $orphanedReports);

        if ($employees->isEmpty() && $users->isEmpty() && $companies->isEmpty() && $seededLogs === 0) {
            $this->info('  Nothing to remove — this database has no seeded demo data.');
            $this->line('');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('  <fg=yellow>Dry run — nothing was changed.</>');
            $this->line('');

            return self::SUCCESS;
        }

        if (! $this->confirmDestruction($collateralLogs->count())) {
            $this->line('  Nothing was changed.');
            $this->line('');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($employees, $users, $companies) {
                AttendanceLog::where('source', self::SEEDED_SOURCE)->delete();

                // Break the reporting line first. The column is SET NULL on
                // delete, but doing it explicitly keeps the intent visible and
                // works the same on a schema where somebody tightened the rule.
                Employee::whereIn('manager_id', $employees->pluck('id'))
                    ->update(['manager_id' => null]);

                foreach ($employees as $employee) {
                    $employee->delete();     // cascades attendance, leave, balances
                }

                foreach ($users as $user) {
                    $user->tokens()->delete();    // polymorphic: no cascade to rely on
                    $user->delete();              // cascades push_devices
                }

                foreach ($companies as $company) {
                    $company->delete();
                }
            });
        } catch (Throwable $e) {
            $this->error('  Nothing was deleted: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('  Demo data removed.');
        $this->line('  <fg=gray>If no administrator is left, create one: php artisan emp:install --force</>');
        $this->line('');

        return self::SUCCESS;
    }

    protected function render($employees, $users, $companies, int $seededLogs, $collateralLogs, int $collateralLeave, $orphanedReports): void
    {
        $this->line('');
        $this->line('  <options=bold>Seeded rows in this database</>');
        $this->line('');

        $this->section('Users', $users->map(fn (User $u) => "{$u->name} <{$u->email}>")->all());
        $this->section('Employees', $employees->map(fn (Employee $e) => "{$e->employee_code}  {$e->first_name} {$e->last_name}")->all());
        $this->section('Empty companies', $companies->map(fn (Company $c) => "#{$c->id}  {$c->name}")->all());
        $this->section('Attendance', $seededLogs ? ["{$seededLogs} rows with source='kiosk'"] : []);

        if ($collateralLogs->isNotEmpty()) {
            $this->line('');
            $this->line('  <fg=red;options=bold>Real attendance that would go with them</>');
            $this->line('  <fg=gray>These were not seeded. They belong to a demo employee, and</>');
            $this->line('  <fg=gray>attendance cascades on delete, so removing that employee removes these.</>');
            $this->line('');

            foreach ($collateralLogs as $log) {
                $this->line(sprintf(
                    '    <fg=red>#%-5s</> employee %-4s %-4s %-8s %s',
                    $log->id,
                    $log->employee_id,
                    $log->type,
                    $log->source,
                    $log->scanned_at,
                ));
            }
        }

        if ($collateralLeave > 0) {
            $this->line('');
            $this->line("  <fg=yellow>{$collateralLeave} leave request(s)</> attached to those employees would go too.");
        }

        if ($orphanedReports->isNotEmpty()) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>Staff who would lose their manager</>');
            $this->line('  <fg=gray>They survive, but nobody approves their leave until a new manager is set.</>');
            $this->line('');

            foreach ($orphanedReports as $report) {
                $this->line("    {$report->employee_code}  {$report->first_name} {$report->last_name}");
            }
        }

        $this->line('');
    }

    /** @param array<int, string> $lines */
    protected function section(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->line("  <options=bold>{$title}</> <fg=gray>(" . count($lines) . ')</>');

        foreach ($lines as $line) {
            $this->line("    <fg=gray>-</> {$line}");
        }

        $this->line('');
    }

    protected function confirmDestruction(int $collateral): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }

        if ($collateral > 0) {
            $this->line("  <fg=red;options=bold>{$collateral} real attendance row(s) will be destroyed along with the seeded ones.</>");
            $this->line('  <fg=gray>Spare the employee they belong to with --keep-employees=CODE if that is wrong.</>');
            $this->line('');
        }

        return $this->confirm('Delete everything listed above? There is no undo.', false);
    }
}
