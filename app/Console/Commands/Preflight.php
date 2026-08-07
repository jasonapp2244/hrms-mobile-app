<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Check that a production install is actually fit to take traffic.
 *
 * Almost everything this looks for fails silently. A missing queue worker does
 * not error — the bell still lights up and no email is ever sent. A missing cron
 * line does not error — shifts simply never close and no backup is ever taken.
 * MAIL_MAILER left on 'log' does not error — approvals are written to a file
 * nobody reads. Each of those looks like a working system from the dashboard,
 * and the first evidence is weeks of wrong data.
 *
 * So the checks here are deliberately end-to-end where they can be: not "is the
 * backup path configured" but "did a backup actually appear in the last day and
 * a half", not "is the queue driver set" but "is anything draining the queue".
 * Configuration that is present but inert is the failure mode being hunted.
 *
 * Exit codes: 0 if nothing failed (warnings still exit 0), 1 if any check
 * failed — so deploy.sh can run it as the last step and stop on a bad install.
 */
class Preflight extends Command
{
    protected $signature = 'hrms:preflight
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Verify this install is correctly configured for production';

    /** @var array<int, array{level: string, name: string, detail: string}> */
    protected array $results = [];

    /**
     * Whether the database answered. Several checks below query it, and on a box
     * where MySQL is down every one of them would throw the same connection
     * error — burying the one useful line under a dozen repeats of it.
     */
    protected bool $databaseUp = false;

    private const PASS = 'pass';
    private const WARN = 'warn';
    private const FAIL = 'fail';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>HR & Attendance — production preflight</>');
        $this->line('');

        $this->checkEnvironment();
        $this->checkUrlAndTls();
        $this->checkDatabase();
        $this->checkCompanyTimezones();
        $this->checkFilesystem();
        $this->checkCaches();
        $this->checkMail();
        $this->checkQueueWorker();
        $this->checkScheduler();
        $this->checkBackups();
        $this->checkPush();
        $this->checkDefaultCredentials();

        return $this->report();
    }

    // -------------------------------------------------------------------------
    // Checks
    // -------------------------------------------------------------------------

    protected function checkEnvironment(): void
    {
        $this->assert(
            'APP_ENV',
            app()->environment('production') ? self::PASS : self::WARN,
            "is '" . app()->environment() . "', expected 'production'",
            "production",
        );

        // Debug mode prints the environment — database password, mail password,
        // APP_KEY — onto the error page of anyone who can provoke an exception.
        // Nothing else on this list is as immediately dangerous.
        $this->assert(
            'APP_DEBUG',
            config('app.debug') ? self::FAIL : self::PASS,
            'is ON — error pages will leak credentials to visitors',
            'off',
        );

        $this->assert(
            'APP_KEY',
            config('app.key') ? self::PASS : self::FAIL,
            'is empty — sessions and encrypted values cannot work',
            'set',
        );

        // The demo panel publishes working credentials, an administrator's
        // among them, on a page that needs no account to reach. config/demo.php
        // already forces it off when APP_ENV is production — this catches the
        // case that slips past: a config cache built on a developer machine,
        // where the flag was true, shipped as part of the release.
        $this->assert(
            'Demo quick-login',
            config('demo.quick_login') ? self::FAIL : self::PASS,
            'is ON — the login page is publishing working credentials to visitors',
            'off',
        );

        // Deliberately UTC: timestamps are stored in UTC and each company
        // applies its own timezone on top (Company::tz), so a company-specific
        // value here would be wrong for every other company. Only flag a change
        // away from it, which would shift what "today" means for the whole
        // scheduler.
        $this->assert(
            'App timezone',
            config('app.timezone') === 'UTC' ? self::PASS : self::WARN,
            "is '" . config('app.timezone') . "' — this should stay UTC; "
                . 'per-company time comes from the company record, not from here',
            'UTC (per-company offsets applied on top)',
        );
    }

    protected function checkUrlAndTls(): void
    {
        $url = (string) config('app.url');

        $isLocal = Str::contains($url, ['localhost', '127.0.0.1', '::1']);
        $isHttps = Str::startsWith($url, 'https://');

        $this->assert(
            'APP_URL',
            match (true) {
                $isLocal    => self::FAIL,
                ! $isHttps  => self::FAIL,
                default     => self::PASS,
            },
            $isLocal
                ? "is '{$url}' — the mobile app and app-store listing cannot use a local address"
                : "is '{$url}' — must be https:// or generated links will be insecure",
            $url,
        );

        // Without this the session cookie travels on any plain-http request to
        // the domain, which hands the whole session to anyone on the path.
        $this->assert(
            'Secure session cookie',
            config('session.secure') ? self::PASS : ($isHttps ? self::FAIL : self::WARN),
            'SESSION_SECURE_COOKIE is not true',
            'on',
        );

        // Behind nginx this is what keeps the per-punch IP meaningful. Left
        // unset, every attendance row records the proxy's address instead of the
        // employee's and the column quietly becomes worthless.
        $proxies = env('TRUSTED_PROXIES');
        $this->assert(
            'TRUSTED_PROXIES',
            $proxies ? self::PASS : self::WARN,
            'is unset — if a proxy sits in front of PHP, every punch will record the proxy IP',
            $proxies === '*' ? '* (only safe if the app port is unreachable directly)' : (string) $proxies,
        );
    }

    protected function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->assert('Database', self::FAIL, 'cannot connect: ' . $e->getMessage());

            return;
        }

        $this->databaseUp = true;

        $this->assert('Database', self::PASS, '', config('database.default'));

        // A half-migrated schema is the state where the site loads and then
        // 500s on whichever page touches the missing column.
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->assert('Migrations', self::FAIL, 'have never been run on this database');

                return;
            }

            $files   = array_keys($migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]));
            $ran     = $migrator->getRepository()->getRan();
            $pending = array_diff($files, $ran);

            $this->assert(
                'Migrations',
                $pending === [] ? self::PASS : self::FAIL,
                count($pending) . ' pending: ' . implode(', ', array_slice($pending, 0, 3))
                    . (count($pending) > 3 ? ' …' : ''),
                'up to date',
            );
        } catch (Throwable $e) {
            $this->assert('Migrations', self::WARN, 'could not be checked: ' . $e->getMessage());
        }
    }

    /**
     * Every company needs a real timezone on its record.
     *
     * This is the setting that decides what "09:00" means, so a blank or
     * misspelled one silently falls back to UTC — and a workforce five hours off
     * UTC is then marked late every single morning. It is worth checking because
     * the failure produces plausible-looking data rather than an error.
     */
    protected function checkCompanyTimezones(): void
    {
        if (! $this->databaseUp || ! Schema::hasTable('companies')) {
            return;
        }

        $valid = timezone_identifiers_list();

        $bad = DB::table('companies')
            ->select('id', 'name', 'timezone')
            ->get()
            ->reject(fn ($company) => $company->timezone && in_array($company->timezone, $valid, true))
            ->pluck('name')
            ->all();

        $this->assert(
            'Company timezones',
            $bad === [] ? self::PASS : self::FAIL,
            'missing or invalid, so these fall back to UTC and their staff will be '
                . 'judged late against the wrong clock: ' . implode(', ', $bad),
            'all set',
        );
    }

    protected function checkFilesystem(): void
    {
        $paths = [
            'storage/logs'             => storage_path('logs'),
            'storage/framework'        => storage_path('framework'),
            'storage/app'              => storage_path('app'),
            'bootstrap/cache'          => base_path('bootstrap/cache'),
        ];

        $bad = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        $this->assert(
            'Writable paths',
            $bad === [] ? self::PASS : self::FAIL,
            'not writable by the web user: ' . implode(', ', $bad),
            'all writable',
        );
    }

    protected function checkCaches(): void
    {
        // Not correctness, but the difference is on every single request, and
        // rebuilding these is one line of the deploy script.
        $this->assert(
            'Config cache',
            app()->configurationIsCached() ? self::PASS : self::WARN,
            'not cached — run: php artisan config:cache',
            'cached',
        );

        $this->assert(
            'Route cache',
            app()->routesAreCached() ? self::PASS : self::WARN,
            'not cached — run: php artisan route:cache',
            'cached',
        );
    }

    protected function checkMail(): void
    {
        $mailer = config('mail.default');

        // The most-missed setting in this deploy. Leave approvals and rejections
        // are built, queued and tested — and with 'log' the employee is never
        // actually told anything.
        $this->assert(
            'MAIL_MAILER',
            in_array($mailer, ['log', 'array'], true) ? self::FAIL : self::PASS,
            "is '{$mailer}' — mail is written to a file and delivered to nobody",
            $mailer,
        );

        $from = config('mail.from.address');
        $this->assert(
            'Mail from address',
            $from && ! Str::contains($from, 'example.com') ? self::PASS : self::WARN,
            "is '{$from}' — set an address on a domain whose SPF and DKIM you control",
            (string) $from,
        );
    }

    protected function checkQueueWorker(): void
    {
        if (! $this->databaseUp) {
            return;   // already reported; the queue lives in the same database
        }

        if (config('queue.default') === 'sync') {
            // Sync means the punch request itself waits for the push and the
            // SMTP round-trip before it returns.
            $this->assert('Queue', self::FAIL, "is 'sync' — notifications run inside the web request");

            return;
        }

        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            $this->assert('Queue worker', self::WARN, 'cannot be checked for this queue driver', config('queue.default'));

            return;
        }

        // The real question is not "is a worker configured" but "is anything
        // draining the queue". A job that has been ready to run for minutes and
        // is still sitting there answers it.
        $oldest = DB::table('jobs')
            ->where('available_at', '<=', now()->getTimestamp())
            ->min('available_at');

        if ($oldest === null) {
            $this->assert('Queue worker', self::PASS, '', 'queue empty');
        } else {
            $waited = now()->getTimestamp() - (int) $oldest;

            $this->assert(
                'Queue worker',
                $waited > 300 ? self::FAIL : self::PASS,
                'a job has been waiting ' . $this->humanise($waited)
                    . ' — no worker appears to be running (systemctl status hrms-worker)',
                'draining',
            );
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();

            $this->assert(
                'Failed jobs',
                $failed === 0 ? self::PASS : self::WARN,
                "{$failed} in the failed_jobs table — inspect with: php artisan queue:failed",
                'none',
            );
        }
    }

    protected function checkScheduler(): void
    {
        // There is no reliable "when did schedule:run last fire" record, so this
        // infers it from the one thing the scheduler is supposed to produce on a
        // fixed daily rhythm: the nightly backup. No recent backup means either
        // cron is not running or the backup is failing — both need looking at,
        // and the backup check below tells them apart.
        $newest = $this->newestBackupTimestamp();

        if ($newest === null) {
            $this->assert(
                'Scheduler',
                self::FAIL,
                'no backup has ever been produced — the cron entry is probably missing '
                    . '(see deploy/hrms-scheduler.cron)',
            );

            return;
        }

        $age = time() - $newest;

        $this->assert(
            'Scheduler',
            $age > 129600 ? self::FAIL : self::PASS,   // 36 hours
            'the last scheduled backup ran ' . $this->humanise($age)
                . ' ago — cron may not be running',
            'ran ' . $this->humanise($age) . ' ago',
        );
    }

    protected function checkBackups(): void
    {
        $dir = (string) config('backup.path');

        if (! is_dir($dir)) {
            $this->assert('Backup path', self::FAIL, "does not exist: {$dir}");
        } elseif (! is_writable($dir)) {
            $this->assert('Backup path', self::FAIL, "is not writable: {$dir}");
        } else {
            // A backup directory under public/ is every employee record
            // downloadable by anyone who guesses the filename.
            $public = realpath(public_path());
            $real   = realpath($dir);

            $this->assert(
                'Backup path',
                $public && $real && Str::startsWith($real, $public) ? self::FAIL : self::PASS,
                "is inside the web root ({$dir}) — dumps would be downloadable over HTTP",
                $dir,
            );
        }

        // db:backup shells out to these. On Windows under XAMPP they are not on
        // PATH, which is why they are configurable at all.
        foreach (['mysqldump' => config('backup.mysqldump'), 'mysql' => config('backup.mysql')] as $label => $binary) {
            $found = $this->binaryExists((string) $binary);

            $this->assert(
                "Backup: {$label}",
                $found ? self::PASS : self::FAIL,
                "'{$binary}' was not found — nightly backups will fail",
                (string) $binary,
            );
        }
    }

    protected function checkPush(): void
    {
        if (! config('fcm.enabled')) {
            $this->assert('Push (FCM)', self::WARN, 'is disabled — the mobile app will receive no notifications', 'off');

            return;
        }

        $problems = [];

        if (! config('fcm.project_id')) {
            $problems[] = 'FCM_PROJECT_ID is empty';
        }

        $credentials = (string) config('fcm.credentials');

        if (! is_readable($credentials)) {
            $problems[] = "the service-account key is missing or unreadable at {$credentials}";
        }

        $this->assert(
            'Push (FCM)',
            $problems === [] ? self::PASS : self::FAIL,
            'is enabled but ' . implode('; ', $problems),
            'configured',
        );
    }

    protected function checkDefaultCredentials(): void
    {
        if (! $this->databaseUp || ! Schema::hasTable('users')) {
            return;
        }

        try {
            // Only the accounts that can reach the dashboard, and only a handful
            // of them — every check here is a deliberately slow bcrypt compare.
            $accounts = User::role(['admin', 'hr'])->limit(25)->get(['id', 'email', 'password']);
        } catch (Throwable) {
            return;   // roles not seeded yet; nothing meaningful to check
        }

        $weak = $accounts
            ->filter(fn (User $user) => Hash::check('password', $user->password))
            ->pluck('email')
            ->all();

        $this->assert(
            'Demo credentials',
            $weak === [] ? self::PASS : self::FAIL,
            'these accounts still use the seeded password "password": ' . implode(', ', $weak),
            'no default passwords',
        );
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /**
     * Record a check, and print it as it happens so a slow run still shows
     * progress rather than sitting silent and then dumping everything.
     */
    protected function assert(string $name, string $level, string $detail = '', string $ok = 'ok'): void
    {
        $this->results[] = compact('level', 'name', 'detail');

        [$mark, $colour, $text] = match ($level) {
            self::PASS => ['  OK  ', 'green',  $ok],
            self::WARN => [' WARN ', 'yellow', $detail],
            default    => [' FAIL ', 'red',    $detail],
        };

        $this->line(sprintf(
            '  <fg=%s>%s</> %s  <fg=gray>%s</>',
            $colour,
            $mark,
            str_pad($name, 22),
            $text,
        ));
    }

    protected function report(): int
    {
        $failed = collect($this->results)->where('level', self::FAIL)->count();
        $warned = collect($this->results)->where('level', self::WARN)->count();
        $passed = collect($this->results)->where('level', self::PASS)->count();

        $this->line('');
        $this->line("  {$passed} passed, {$warned} warning(s), {$failed} failure(s)");
        $this->line('');

        if ($failed > 0) {
            $this->error('  Not ready for production. Fix the failures above.');

            return self::FAILURE;
        }

        if ($warned > 0 && $this->option('strict')) {
            $this->error('  Warnings present and --strict was given.');

            return self::FAILURE;
        }

        $this->info('  Ready.');

        return self::SUCCESS;
    }

    /** Newest backup file's mtime, or null when there are none. */
    protected function newestBackupTimestamp(): ?int
    {
        $dir = (string) config('backup.path');

        if (! is_dir($dir)) {
            return null;
        }

        $newest = null;

        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.sql*') ?: [] as $file) {
            $newest = max($newest ?? 0, (int) filemtime($file));
        }

        return $newest;
    }

    /** Is this binary runnable — either an absolute path, or something on PATH. */
    protected function binaryExists(string $binary): bool
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_file($binary);
        }

        $probe = stripos(PHP_OS_FAMILY, 'win') === 0
            ? "where {$binary} 2>NUL"
            : "command -v " . escapeshellarg($binary) . " 2>/dev/null";

        return ! empty(@shell_exec($probe));
    }

    protected function humanise(int $seconds): string
    {
        return match (true) {
            $seconds < 120   => "{$seconds}s",
            $seconds < 7200  => intdiv($seconds, 60) . 'm',
            $seconds < 172800 => intdiv($seconds, 3600) . 'h',
            default          => intdiv($seconds, 86400) . 'd',
        };
    }
}
