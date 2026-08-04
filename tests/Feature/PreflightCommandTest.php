<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The preflight command is the last step of a deploy, so what matters is that it
 * actually refuses a bad install rather than printing a reassuring list.
 *
 * Every case below is a misconfiguration that produces no error at runtime: the
 * site loads, the dashboard looks right, and something important silently does
 * not happen. Those are the ones worth pinning, because a check that only
 * catches loud failures adds nothing — the loud ones announce themselves.
 */
class PreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A scratch backup directory per test. The scheduler check works by
        // asking when a backup last appeared, so the tests have to own that
        // directory — otherwise they would pass or fail on whether the developer
        // running them happened to have taken a backup recently.
        $this->backupDir = storage_path('framework/testing/preflight-' . uniqid());
        File::ensureDirectoryExists($this->backupDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    /** Put the environment into a state where the command should be happy. */
    protected function passingConfig(): void
    {
        Config::set('app.debug', false);
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        Config::set('app.url', 'https://hr.example.com');
        Config::set('app.timezone', 'UTC');
        Config::set('session.secure', true);
        Config::set('mail.default', 'smtp');
        Config::set('mail.from.address', 'hr@acme-corp.test');
        Config::set('queue.default', 'database');
        Config::set('backup.path', $this->backupDir);

        // A backup from an hour ago: the evidence the command uses to conclude
        // that cron is running.
        $this->writeBackupAgedHours(1);
    }

    /** Drop a dump in the backup directory with an mtime this many hours old. */
    protected function writeBackupAgedHours(int $hours): void
    {
        $file = $this->backupDir . '/hrms_test.sql.gz';
        File::put($file, 'not a real dump');
        touch($file, time() - ($hours * 3600));
    }

    /** An admin with a password nobody could guess from the repository. */
    protected function safeAdmin(): User
    {
        $user = User::create([
            'name'     => 'Real Admin',
            'email'    => 'admin@acme-corp.test',
            'password' => Hash::make('a-genuinely-different-secret'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    // ================= the failures that are silent in production =================

    public function test_it_fails_when_debug_mode_is_left_on(): void
    {
        $this->passingConfig();
        Config::set('app.debug', true);

        // Worth its own test rather than folding into a general "bad config"
        // case: this one hands the database password and APP_KEY to anyone who
        // can provoke an exception, which is a different order of problem.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('APP_DEBUG')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_mail_is_still_writing_to_the_log(): void
    {
        $this->passingConfig();
        Config::set('mail.default', 'log');

        // The single most-missed setting in this deploy. Leave approvals are
        // queued and "sent" successfully; the employee is simply never told.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('MAIL_MAILER')
            ->assertExitCode(1);
    }

    public function test_it_fails_on_a_localhost_app_url(): void
    {
        $this->passingConfig();
        Config::set('app.url', 'http://localhost');

        // A handset cannot reach localhost, and the app stores will not accept a
        // privacy-policy URL that resolves to one.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('APP_URL')
            ->assertExitCode(1);
    }

    public function test_it_fails_on_a_plain_http_app_url(): void
    {
        $this->passingConfig();
        Config::set('app.url', 'http://hr.example.com');

        $this->artisan('hrms:preflight')->assertExitCode(1);
    }

    public function test_it_fails_when_the_queue_would_run_inside_the_web_request(): void
    {
        $this->passingConfig();
        Config::set('queue.default', 'sync');

        // Not merely slow: a punch would block on the SMTP round-trip and the
        // push to every one of the employee's handsets before it returned.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Queue')
            ->assertExitCode(1);
    }

    // ================= the scheduler =================

    public function test_it_fails_when_no_backup_has_ever_appeared(): void
    {
        $this->passingConfig();
        File::cleanDirectory($this->backupDir);

        // The missing cron line is invisible from the dashboard: shifts never
        // close, nobody is reminded, and no backup is ever taken — all without a
        // single error anywhere.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Scheduler')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_last_backup_is_days_old(): void
    {
        $this->passingConfig();
        File::cleanDirectory($this->backupDir);
        $this->writeBackupAgedHours(72);

        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Scheduler')
            ->assertExitCode(1);
    }

    // ================= credentials =================

    public function test_it_fails_when_a_dashboard_account_still_uses_the_seeded_password(): void
    {
        $this->passingConfig();

        $user = User::create([
            'name'     => 'Demo Admin',
            'email'    => 'admin@hrms.test',
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('admin');

        // The seeded admin is admin@hrms.test / password. Reaching production
        // with it intact is a full administrative account open to anyone who has
        // read the README.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Demo credentials')
            ->assertExitCode(1);
    }

    public function test_it_accepts_a_dashboard_account_with_a_real_password(): void
    {
        $this->passingConfig();
        $this->safeAdmin();

        $this->artisan('hrms:preflight')
            ->doesntExpectOutputToContain('still use the seeded password')
            ->assertExitCode(0);
    }

    public function test_it_ignores_employees_who_are_not_dashboard_users(): void
    {
        $this->passingConfig();
        $this->safeAdmin();

        $staff = User::create([
            'name'     => 'Ann Lee',
            'email'    => 'ann@acme-corp.test',
            'password' => Hash::make('password'),
        ]);
        $staff->assignRole('employee');

        // Employees cannot reach the dashboard at all, and their credentials are
        // issued per person rather than seeded. Flagging them would make this
        // check noisy on every install and get it ignored.
        $this->artisan('hrms:preflight')
            ->doesntExpectOutputToContain('still use the seeded password')
            ->assertExitCode(0);
    }

    // ================= timezone =================

    public function test_it_fails_when_a_company_has_no_usable_timezone(): void
    {
        $this->passingConfig();

        Company::create(['name' => 'Acme', 'timezone' => 'Not/AZone', 'currency' => 'USD']);

        // Falls back to UTC, so nothing errors — the whole company is just
        // marked late every morning by however far they are from UTC.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Company timezones')
            ->assertExitCode(1);
    }

    public function test_it_accepts_a_company_with_a_real_timezone(): void
    {
        $this->passingConfig();

        Company::create(['name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD']);

        $this->artisan('hrms:preflight')
            ->doesntExpectOutputToContain('judged late against the wrong clock')
            ->assertExitCode(0);
    }

    // ================= warnings vs failures =================

    public function test_warnings_alone_do_not_fail_the_deploy(): void
    {
        $this->passingConfig();

        // TRUSTED_PROXIES unset and FCM off are both warnings: real things to
        // look at, but not reasons to refuse to ship. A check that fails on
        // everything gets ignored, which is worse than not having it.
        $this->artisan('hrms:preflight')->assertExitCode(0);
    }

    public function test_strict_mode_promotes_warnings_to_failures(): void
    {
        $this->passingConfig();

        $this->artisan('hrms:preflight --strict')->assertExitCode(1);
    }

    // ================= push =================

    public function test_it_fails_when_push_is_switched_on_without_a_key(): void
    {
        $this->passingConfig();
        Config::set('fcm.enabled', true);
        Config::set('fcm.project_id', 'hrms-demo');
        Config::set('fcm.credentials', $this->backupDir . '/no-such-key.json');

        // Half-configured is worse than off: every notification fails into
        // failed_jobs instead of quietly not being sent.
        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('Push (FCM)')
            ->assertExitCode(1);
    }

    // ================= it survives a broken box =================

    public function test_it_reports_rather_than_crashes_when_the_database_is_unreachable(): void
    {
        $this->passingConfig();

        // A file-backed SQLite connection in a directory that does not exist:
        // unreachable in the same way production's MySQL would be, but it fails
        // immediately instead of waiting out a network timeout.
        Config::set('database.connections.broken', [
            'driver'   => 'sqlite',
            'database' => $this->backupDir . '/no-such-dir/db.sqlite',
            'prefix'   => '',
        ]);
        Config::set('database.default', 'broken');

        try {
            // A box with a dead database is exactly when someone runs this, so it
            // has to produce the diagnosis instead of a stack trace — and must
            // not then repeat that same error for every later check that queries.
            $this->artisan('hrms:preflight')
                ->expectsOutputToContain('Database')
                ->assertExitCode(1);
        } finally {
            // Put the default back, or RefreshDatabase's rollback in tearDown
            // tries to roll back the broken connection instead of the real one.
            Config::set('database.default', 'sqlite');
        }
    }
}
