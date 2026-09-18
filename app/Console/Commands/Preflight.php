<?php

namespace App\Console\Commands;

use App\Http\Middleware\DetectUntrustedProxy;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
    protected $signature = 'emp:preflight
                            {--strict : Treat warnings as failures too}
                            {--non-production : Staging or demo box — downgrade the failures that can be a deliberate choice there, but never the ones that cannot}';

    protected $description = 'Verify this install is correctly configured for production';

    /** @var array<int, array{level: string, name: string, detail: string}> */
    protected array $results = [];

    /**
     * Whether the database answered. Several checks below query it, and on a box
     * where MySQL is down every one of them would throw the same connection
     * error — burying the one useful line under a dozen repeats of it.
     */
    protected bool $databaseUp = false;

    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>' . config('app.name') . ' — production preflight</>');
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
        $this->checkMobileGate();
        $this->checkDefaultCredentials();
        $this->checkDependencyAdvisories();

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
        //
        // Read through config rather than `env()`. A deploy runs `config:cache`,
        // and once it has, .env is not loaded at all — so an `env()` here would
        // report "unset" on exactly the box that had just set it correctly, and
        // the one check that can catch this would be the thing crying wolf.
        $proxies = config('trustedproxy.proxies');

        // Unset is not itself a fault — on a single-server install it is the
        // right answer, and this used to warn at every one of them. What turns
        // it into a fault is a proxy actually being in front, which
        // configuration cannot tell us and traffic can: `DetectUntrustedProxy`
        // leaves a marker the first time a forwarded request arrives with
        // nothing trusted. That is proof the column is wrong *now*, so it fails
        // the deploy rather than warning about it.
        $sighting = $proxies ? null : $this->proxySighting();

        $this->assert(
            'TRUSTED_PROXIES',
            match (true) {
                (bool) $proxies => self::PASS,
                $sighting !== null => self::FAIL,
                default => self::WARN,
            },
            $sighting !== null
                ? sprintf(
                    'is unset and a proxy is in front — %s arrived claiming %s, and the punch recorded %s. Every IP stored since is the proxy\'s.',
                    $sighting['header'] ?? 'a forwarding header',
                    $sighting['claimed'] ?? 'a client address',
                    $sighting['recorded'] ?? 'the proxy',
                )
                : 'is unset — if a proxy sits in front of PHP, every punch will record the proxy IP',
            $proxies === '*' ? '* (only safe if the app port is unreachable directly)' : (string) $proxies,
        );
    }

    /**
     * The marker `DetectUntrustedProxy` leaves, or null if it cannot be read.
     *
     * Wrapped because the default cache store is the database, this check runs
     * before `checkDatabase()`, and a preflight that throws is a preflight that
     * reports nothing at all. **That is the one failure mode this command must
     * not have** — it exists to be run on boxes that are misconfigured, and the
     * box with an unreachable database is precisely one of them. Caught here,
     * the run continues and `checkDatabase()` says the true thing a few lines
     * later; uncaught, a stack trace replaces the entire report.
     *
     * No evidence is not evidence of absence, so this falls back to the WARN
     * the check gives when it cannot tell, never to a PASS.
     */
    protected function proxySighting(): ?array
    {
        try {
            $sighting = Cache::get(DetectUntrustedProxy::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($sighting) ? $sighting : null;
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

        $this->checkStorageLink();
    }

    /**
     * public/storage has to exist, or every employee photo silently 404s.
     *
     * Worth its own line because the failure is invisible: nothing errors, no
     * log entry is written, the pages all render — the pictures are simply
     * missing, and the first report of it comes from a user weeks later.
     *
     * A WARN rather than a FAIL: the deploy script runs `storage:link` anyway,
     * so a fresh checkout that has not run it yet is not a reason to refuse a
     * deployment. Document-vault files are unaffected either way — those are on
     * the private disk and are streamed through the app, never linked.
     */
    protected function checkStorageLink(): void
    {
        $link = public_path('storage');

        // file_exists rather than is_link or is_dir. Those two disagree with
        // each other across platforms — a Windows junction is not a link, and a
        // symlink PHP cannot resolve is not a dir — and the question here is
        // only whether the web server will find something at that path.
        $this->assert(
            'Public storage link',
            file_exists($link) ? self::PASS : self::WARN,
            'public/storage is missing — employee photos will 404. Run: php artisan storage:link',
            'present',
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
                $waited > $this->queueTolerance() ? self::FAIL : self::PASS,
                'a job has been waiting ' . $this->humanise($waited)
                    . ' — nothing appears to be draining the queue (' . $this->queueHint() . ')',
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

    /**
     * Whether this install is shared webspace rather than a server we own.
     *
     * The distinction matters only to the two checks below. Everything else
     * preflight asserts is true of both.
     */
    protected function managed(): bool
    {
        return config('hosting.mode') === 'managed';
    }

    /**
     * How long the oldest ready job may have been waiting before the queue
     * counts as stalled.
     *
     * On a daemon the gap between drains is seconds. On managed hosting it is
     * the cron interval, so the tolerance has to clear that with room for a
     * missed run — otherwise a healthy install fails its own deploy.
     */
    protected function queueTolerance(): int
    {
        $configured = config('hosting.queue_max_wait');

        if ($configured !== null && $configured !== '') {
            return (int) $configured;
        }

        return $this->managed()
            ? (int) config('hosting.managed_cron_minutes', 5) * 60 * 3
            : 300;
    }

    /** Where to go and look, which is a different place in each mode. */
    protected function queueHint(): string
    {
        return $this->managed()
            ? 'check the queue:work cron entry — see deploy/emp-webspace.cron'
            : 'systemctl status emp-worker';
    }

    /** @see queueHint() */
    protected function schedulerCronFile(): string
    {
        return $this->managed() ? 'deploy/emp-webspace.cron' : 'deploy/emp-scheduler.cron';
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
                    . '(see ' . $this->schedulerCronFile() . ')',
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

        // db:backup prefers these. On Windows under XAMPP they are not on PATH,
        // which is why they are configurable at all; on managed webspace they
        // are frequently absent altogether.
        //
        // Neither absence fails a deploy any more, and the difference between
        // them matters. Without mysqldump the dump is still taken, in PHP.
        // Without the mysql client it cannot be restored to prove it reads
        // back — the same standing warning as a host that will not let the
        // user create a scratch database. Failing here instead used to block a
        // deploy over a backup that would have been written perfectly well.
        $dumpFound = $this->binaryExists((string) config('backup.mysqldump'));

        $this->assert(
            'Backup: mysqldump',
            $dumpFound ? self::PASS : self::WARN,
            "'" . config('backup.mysqldump') . "' was not found — dumps will be written through PHP instead",
            $dumpFound ? (string) config('backup.mysqldump') : 'PHP fallback',
        );

        $clientFound = $this->binaryExists((string) config('backup.mysql'));

        $this->assert(
            'Backup: mysql',
            $clientFound ? self::PASS : self::WARN,
            "'" . config('backup.mysql') . "' was not found — dumps cannot be verified automatically; restore one by hand",
            $clientFound ? (string) config('backup.mysql') : 'unverified',
        );
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

    /**
     * The app gate (B6.6), which is the one setting here that can stop an
     * entire company clocking in.
     */
    protected function checkMobileGate(): void
    {
        // A maintenance window is deliberate while it lasts, and forgotten
        // afterwards — at which point every handset in the company shows a
        // "back shortly" screen and nobody can record attendance. This is a
        // failure rather than a warning for the same reason APP_DEBUG is: the
        // only way to leave it on and still pass is to mean it.
        $this->assert(
            'App maintenance mode',
            config('mobile.maintenance') ? self::FAIL : self::PASS,
            'is ON — every handset is being shown a "back shortly" screen and '
                . 'nobody can clock in from the app',
            'off',
        );

        $minimum = (string) config('mobile.minimum_version');

        if ($minimum === '') {
            $this->assert('App minimum version', self::PASS, '', 'no floor — every build accepted');

            return;
        }

        // A floor with no store link is an update screen whose button does
        // nothing. Both platforms, because the fleet is never one of them.
        $missing = [];

        foreach (['android' => 'Play Store', 'ios' => 'App Store'] as $platform => $store) {
            if ((string) config("mobile.store_url.{$platform}") === '') {
                $missing[] = $store;
            }
        }

        $this->assert(
            'App minimum version',
            $missing === [] ? self::PASS : self::FAIL,
            "is {$minimum} but there is no link to the "
                . implode(' or the ', $missing)
                . ' — the update screen would have nowhere to send anybody',
            $minimum,
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
     * Known security advisories against the installed packages.
     *
     * The dependency register carried this as an open finding for months — "no
     * automated check exists" — and the first time anybody ran `composer audit`
     * by hand it found three, one of them a high-severity path traversal in
     * `maatwebsite/excel` that this application was actively feeding
     * caller-controlled filenames to. Fixing what one manual run turned up is
     * not the same as having a check; this is the check.
     *
     * **Severity decides the verdict.** Critical and high fail the deploy,
     * because shipping a known remote-exploitable hole is not a judgement call.
     * Medium and low warn: they are worth reading before release, but blocking
     * an urgent fix on a low-severity advisory in a dev-only package would
     * teach everybody to pass `--strict=false` and stop reading the output.
     *
     * **It never fails on its own inability to run.** The advisory database is
     * fetched over the network, and plenty of production boxes have no outbound
     * access at all — a check that turned "I could not look" into "you may not
     * deploy" would be the fastest way to get itself deleted. Composer missing,
     * the network down, a timeout: all of it warns and says which.
     */
    protected function checkDependencyAdvisories(): void
    {
        if (! $this->binaryExists('composer')) {
            $this->assert(
                'Dependency advisories',
                self::WARN,
                'composer is not on PATH here, so advisories could not be checked — run `composer audit` where it is',
            );

            return;
        }

        [$level, $detail, $ok] = self::advisoryVerdict($this->runComposerAudit());

        $this->assert('Dependency advisories', $level, $detail, $ok);
    }

    /**
     * Turn a decoded `composer audit` report into a verdict.
     *
     * Pure, and separate from running composer, because the cases worth testing
     * are the ones a passing machine cannot produce: a high-severity advisory,
     * a medium-only one, and a run that could not happen at all. A check whose
     * failure path has never been executed is a check nobody should trust.
     *
     * @param  array<string, mixed>|null  $report  null when composer could not answer
     * @return array{0: string, 1: string, 2: string}  [level, detail, ok text]
     */
    public static function advisoryVerdict(?array $report): array
    {
        if ($report === null) {
            return [
                self::WARN,
                'could not be checked — composer audit did not answer (no network, or it timed out)',
                '',
            ];
        }

        // Keyed by package when populated, a bare empty array when not — so it
        // is flattened rather than assumed to be either shape.
        $advisories = collect($report['advisories'] ?? [])->flatten(1);

        if ($advisories->isEmpty()) {
            // `abandoned` is not a vulnerability and fails nothing, but a
            // package nobody maintains is where the next advisory comes from.
            $abandoned = count($report['abandoned'] ?? []);

            return [
                self::PASS,
                '',
                $abandoned > 0 ? "none ({$abandoned} abandoned package(s))" : 'none',
            ];
        }

        // Grouped by severity so the line names the worst thing, not just a count.
        $bySeverity = $advisories->groupBy(fn ($a) => strtolower($a['severity'] ?? 'unknown'));
        $serious    = $bySeverity->only(['critical', 'high'])->flatten(1);

        $summary = $bySeverity
            ->map(fn ($group, $severity) => count($group) . ' ' . $severity)
            ->implode(', ');

        $worst = $serious->isNotEmpty() ? $serious->first() : $advisories->first();

        $detail = sprintf(
            '%s — e.g. %s: %s. Run `composer audit` for the list',
            $summary,
            $worst['packageName'] ?? 'a package',
            Str::limit((string) ($worst['title'] ?? 'see the advisory'), 90),
        );

        return [$serious->isNotEmpty() ? self::FAIL : self::WARN, $detail, ''];
    }

    /**
     * `composer audit --format=json`, decoded — or null if it could not run.
     *
     * Composer exits non-zero when it *finds* advisories, so the exit code says
     * nothing useful here and the JSON is the answer. A run that produces no
     * decodable JSON is the failure case, whatever it exited with.
     *
     * @return array<string, mixed>|null
     */
    protected function runComposerAudit(): ?array
    {
        $command = sprintf(
            'composer audit --format=json --no-interaction --working-dir=%s 2>%s',
            escapeshellarg(base_path()),
            stripos(PHP_OS_FAMILY, 'win') === 0 ? 'NUL' : '/dev/null',
        );

        $output = @shell_exec($command);

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        $report = json_decode($output, true);

        return is_array($report) ? $report : null;
    }

    /**
     * Failures that stay failures on a staging or demo box.
     *
     * `--non-production` exists because a demo install is *meant* to have mail
     * going to the log and the quick-login panel up, and a script that fails on
     * those teaches people to stop running it. But it used to be all-or-nothing
     * — `deploy.sh` ran the whole command with `|| echo "(advisory only)"` —
     * which quietly downgraded every other check too: an empty `APP_KEY`, an
     * administrator still on the seeded password, a corrupted audit trail, a
     * critical CVE.
     *
     * The line between the two lists is whether the state could be somebody's
     * deliberate choice. Mail to the log and a demo panel are choices. **None of
     * these is.** Nobody decides to ship an unencrypted session store, or
     * to publish `password` as an administrator's password on a URL strangers
     * can reach, or to file every punch against the proxy's address, or to run
     * a package with a known hole in it. They are all evidence of something
     * having gone wrong, and the environment does not change that. A database
     * nothing can reach belongs with them: a staging box that cannot read its
     * own data is not a staging box, it is an outage.
     */
    protected const ALWAYS_FATAL = [
        'APP_KEY',
        'Database',
        'Demo credentials',
        'Dependency advisories',
        'TRUSTED_PROXIES',
    ];

    /**
     * Record a check, and print it as it happens so a slow run still shows
     * progress rather than sitting silent and then dumping everything.
     */
    protected function assert(string $name, string $level, string $detail = '', string $ok = 'ok'): void
    {
        // Downgraded rather than hidden: the line still prints, and still says
        // what is wrong. What changes is only whether it stops the deploy.
        $downgraded = $level === self::FAIL
            && $this->option('non-production')
            && ! in_array($name, static::ALWAYS_FATAL, true);

        if ($downgraded) {
            $level = self::WARN;
        }

        $this->results[] = compact('level', 'name', 'detail');

        [$mark, $colour, $text] = match ($level) {
            self::PASS => ['  OK  ', 'green',  $ok],
            self::WARN => [' WARN ', 'yellow', $detail],
            default    => [' FAIL ', 'red',    $detail],
        };

        $this->line(sprintf(
            '  <fg=%s>%s</> %s  <fg=gray>%s</>%s',
            $colour,
            $mark,
            str_pad($name, 22),
            $text,
            $downgraded ? ' <fg=gray>(advisory on a non-production install)</>' : '',
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
            $this->error($this->option('non-production')
                // Naming it matters: on a staging box every other failure has
                // just been downgraded, so somebody reading a red line here
                // needs to know it survived that and is not noise.
                ? '  Failures that no environment excuses. Fix them before this box serves anybody.'
                : '  Not ready for production. Fix the failures above.');

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
