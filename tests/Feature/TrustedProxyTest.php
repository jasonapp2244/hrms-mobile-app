<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose address a punch records, behind a reverse proxy.
 *
 * The app is served through nginx with Varnish in front, so every request
 * arrives from the proxy. Until the proxy is trusted, `$request->ip()` is the
 * proxy's address and three things are quietly wrong — none of which fails,
 * which is why this survived as long as it did:
 *
 *   * **every punch records the proxy**, so the per-punch IP and the IP column
 *     in exports are not evidence of anything;
 *   * **the login rate limiter keys on the address**, so behind one proxy the
 *     whole company shares a bucket and five wrong passwords anywhere locks
 *     everybody out — precisely the failure A1.10 exists to prevent; and
 *   * generated URLs come out `http://`.
 *
 * **The configuration for it had never once been read.** It lived in
 * `bootstrap/app.php` as `if ($proxies = env('TRUSTED_PROXIES'))`, inside the
 * `withMiddleware` closure — which runs on `afterResolving(HttpKernel::class)`,
 * and `Application::handleRequest` triggers that *before* it calls
 * `$kernel->handle()`, which is what loads .env. So the guard read null every
 * time, cached config or not, and `TrustProxies` was never configured. Setting
 * the variable on the server would have changed nothing; the runbook that said
 * it was "an .env line plus a config:cache" was wrong about this one.
 *
 * These tests drive the real punch endpoint through the real global middleware
 * stack, because that ordering is the entire bug and a unit test of a config
 * value would have passed against the broken build.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        Office::create(['company_id' => $company->id, 'name' => 'HQ']);

        $department = Department::create([
            'company_id' => $company->id, 'name' => 'Ops',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $this->user->assignRole('employee');

        Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id,
            'user_id' => $this->user->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);
    }

    /**
     * Clock in as if the request had crossed a proxy at 127.0.0.1, carrying the
     * employee's real address in X-Forwarded-For.
     */
    private function punchThroughProxy(string $forwardedFor = '203.0.113.9'): void
    {
        Sanctum::actingAs($this->user);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson(
                '/api/v1/attendance/check',
                [],
                ['X-Forwarded-For' => $forwardedFor],
            )
            ->assertOk();
    }

    private function recordedIp(): ?string
    {
        return AttendanceLog::latest('id')->first()?->ip_address;
    }

    public function test_a_trusted_proxy_lets_the_employees_own_address_through(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $this->punchThroughProxy();

        // The whole point: the row names the person's address, not the box that
        // relayed it. Before this fix the assertion below read 127.0.0.1 on a
        // build where the feature was entirely absent.
        $this->assertSame('203.0.113.9', $this->recordedIp());
    }

    public function test_an_untrusted_proxy_is_not_believed(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->punchThroughProxy();

        // Correct, and not a bug: with nothing trusted, an X-Forwarded-For
        // header is an unverified claim by whoever sent it. A local install
        // with no proxy in front of it must not let a caller choose the address
        // its own punches are filed under.
        $this->assertSame('127.0.0.1', $this->recordedIp());
    }

    public function test_a_list_of_proxies_is_accepted(): void
    {
        // The deployment shape that needs it: a load balancer's ranges, written
        // as a comma-separated list, which is what TRUSTED_PROXIES carries.
        config(['trustedproxy.proxies' => '10.0.0.1, 127.0.0.1']);

        $this->punchThroughProxy();

        $this->assertSame('203.0.113.9', $this->recordedIp());
    }

    public function test_a_wildcard_trusts_whatever_is_calling(): void
    {
        // Right only where nothing but the proxy can reach the app's port, and
        // documented as such — but it has to work, because it is the value a
        // Cloudflare or managed-host deployment ends up with.
        config(['trustedproxy.proxies' => '*']);

        $this->punchThroughProxy();

        $this->assertSame('203.0.113.9', $this->recordedIp());
    }

    public function test_the_env_variable_reaches_the_key_the_framework_reads(): void
    {
        // **This is the assertion that covers the actual fix**, and the four
        // above do not. They set `trustedproxy.proxies` directly, so they would
        // all have passed against the broken build too — the framework's
        // fallback to that key has always been there. What was missing was
        // anything putting TRUSTED_PROXIES *into* it.
        //
        // So: the file exists, it is named what `TrustProxies` looks for, and
        // the value comes from the variable the runbook and
        // `.env.production.example` both name. Change any one of those three
        // and this fails.
        $this->assertTrue(
            file_exists(config_path('trustedproxy.php')),
            'config/trustedproxy.php is the file TrustProxies falls back to.',
        );

        putenv('TRUSTED_PROXIES=10.1.2.3');
        $_ENV['TRUSTED_PROXIES'] = '10.1.2.3';

        try {
            // Re-evaluated rather than read from the loaded config: the point is
            // what the file *does*, and config was built before this test ran.
            $published = require config_path('trustedproxy.php');
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES']);
        }

        $this->assertArrayHasKey('proxies', $published);
        $this->assertSame('10.1.2.3', $published['proxies']);
    }
}
