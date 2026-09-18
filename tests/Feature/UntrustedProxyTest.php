<?php

namespace Tests\Feature;

use App\Console\Commands\Preflight;
use App\Http\Middleware\DetectUntrustedProxy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A proxy in front of PHP that nothing is trusting.
 *
 * This is the shape of fault `bootstrap/app.php` already carries a paragraph
 * about: `TrustProxies` was never configured, so every punch recorded the
 * proxy's address instead of the employee's, and — in that file's own words —
 * "nothing failed, which is why it survived". The configuration bug was fixed.
 * The silence was not: leave `TRUSTED_PROXIES` unset on a box behind Varnish
 * and `attendance_logs.ip_address` is wrong on every row, the audit trail
 * (C1.10) and the export column (A7.9) with it, and no screen, log or check
 * says a word.
 *
 * `emp:preflight` did look at the setting, but configuration alone cannot tell
 * a single-server install (where unset is correct) from a proxied one (where it
 * is a data-integrity bug). So it warned at both, which means it warned at
 * almost every install that was fine — and a line that is yellow on healthy
 * boxes is a line people stop reading.
 *
 * What settles it is traffic, not configuration. These tests pin both halves:
 * the middleware only records when a forwarded request really arrives with
 * nothing trusted, and the command only fails when it did.
 */
class UntrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->backupDir = storage_path('framework/testing/proxy-' . uniqid());
        File::ensureDirectoryExists($this->backupDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    // ================= the middleware =================

    public function test_it_records_a_forwarded_request_when_nothing_is_trusted(): void
    {
        Config::set('trustedproxy.proxies', null);

        $this->get('/up', ['X-Forwarded-For' => '203.0.113.9']);

        $sighting = Cache::get(DetectUntrustedProxy::CACHE_KEY);

        $this->assertNotNull($sighting, 'the proxy went unnoticed');
        $this->assertSame('X-Forwarded-For', $sighting['header']);

        // Both addresses, because the whole point of the preflight line is to
        // be readable without going and checking anything: this is who the
        // proxy said it was, and this is what we stored instead.
        $this->assertSame('203.0.113.9', $sighting['claimed']);
        $this->assertNotSame('203.0.113.9', $sighting['recorded']);
    }

    public function test_it_records_nothing_when_the_proxy_is_trusted(): void
    {
        Config::set('trustedproxy.proxies', '*');

        $this->get('/up', ['X-Forwarded-For' => '203.0.113.9']);

        // The configured case is the one that must cost nothing at all — this
        // runs on every request of every correctly deployed box.
        $this->assertNull(Cache::get(DetectUntrustedProxy::CACHE_KEY));
    }

    public function test_it_records_nothing_on_a_direct_request(): void
    {
        Config::set('trustedproxy.proxies', null);

        $this->get('/up');

        // Unset with no proxy in front is the correct configuration for a
        // single-server install, and must not be reported as anything.
        $this->assertNull(Cache::get(DetectUntrustedProxy::CACHE_KEY));
    }

    public function test_a_cache_or_via_hop_counts_as_a_proxy(): void
    {
        Config::set('trustedproxy.proxies', null);

        // Varnish in front of PHP is the deployment this was found on, and a
        // cache may add `Via` without adding `X-Forwarded-For` at all.
        $this->get('/up', ['Via' => '1.1 varnish']);

        $sighting = Cache::get(DetectUntrustedProxy::CACHE_KEY);

        $this->assertNotNull($sighting);
        $this->assertSame('Via', $sighting['header']);

        // `Via` carries no address, and the line has to read sensibly anyway.
        $this->assertNull($sighting['claimed']);
    }

    public function test_the_first_sighting_stands_rather_than_every_request(): void
    {
        Config::set('trustedproxy.proxies', null);

        $this->get('/up', ['X-Forwarded-For' => '203.0.113.9']);
        $this->get('/up', ['X-Forwarded-For' => '198.51.100.4']);

        // `Cache::add` writes only when absent. A busy misconfigured box must
        // not pay for a cache write on every hit to keep saying the same thing.
        $this->assertSame(
            '203.0.113.9',
            Cache::get(DetectUntrustedProxy::CACHE_KEY)['claimed'],
        );
    }

    // ================= the preflight check =================

    /** Put the environment into a state where the command is otherwise happy. */
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

        $file = $this->backupDir . '/emp_test.sql.gz';
        File::put($file, 'not a real dump');
        touch($file, time() - 3600);

        $user = User::create([
            'name'     => 'Real Admin',
            'email'    => 'admin@acme-corp.test',
            'password' => Hash::make('a-genuinely-different-secret'),
        ]);
        $user->assignRole('admin');
    }

    public function test_it_fails_the_deploy_once_a_proxy_has_been_seen(): void
    {
        $this->passingConfig();
        Config::set('trustedproxy.proxies', null);

        // The evidence a real forwarded request would have left.
        Cache::put(DetectUntrustedProxy::CACHE_KEY, [
            'header'   => 'X-Forwarded-For',
            'claimed'  => '203.0.113.9',
            'recorded' => '127.0.0.1',
            'at'       => now()->toIso8601String(),
        ], now()->addDay());

        $this->artisan('emp:preflight')
            ->expectsOutputToContain('TRUSTED_PROXIES')
            ->assertExitCode(1);
    }

    public function test_it_does_not_fail_a_single_server_install(): void
    {
        $this->passingConfig();
        Config::set('trustedproxy.proxies', null);
        Cache::forget(DetectUntrustedProxy::CACHE_KEY);

        // Unset and nothing forwarding: the correct configuration, and it must
        // not fail a deploy. This is the case the old unconditional warning got
        // wrong often enough to make the line worth ignoring.
        $this->artisan('emp:preflight')->assertExitCode(0);
    }

    public function test_a_configured_proxy_ignores_a_stale_sighting(): void
    {
        $this->passingConfig();
        Config::set('trustedproxy.proxies', '*');

        // Left over from before somebody fixed the setting. The fix is the
        // answer; a marker with a week to live must not keep failing deploys
        // after the thing it described has been dealt with.
        Cache::put(DetectUntrustedProxy::CACHE_KEY, [
            'header'   => 'X-Forwarded-For',
            'claimed'  => '203.0.113.9',
            'recorded' => '127.0.0.1',
            'at'       => now()->subDays(3)->toIso8601String(),
        ], now()->addDay());

        $this->artisan('emp:preflight')->assertExitCode(0);
    }
}
