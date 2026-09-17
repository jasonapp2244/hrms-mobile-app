<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API's front door: token login, identity, and revocation.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $department = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'user_id' => $this->user->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);
    }

    /**
     * Forget any guard already resolved in this process.
     *
     * The test container survives between requests, so a guard that resolved a
     * user on an earlier call will hand the same one back on the next — which
     * would make a revoked token look like it still works. Production has a
     * fresh process per request; this is what makes the test match it.
     */
    protected function asNewRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function credentials(array $overrides = []): array
    {
        return array_merge([
            'email'       => 'ann@acme.test',
            'password'    => 'password',
            'device_name' => 'Ann Pixel',
        ], $overrides);
    }

    // ================= ping =================

    public function test_ping_needs_no_token(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJson(['ok' => true, 'version' => 'v1']);
    }

    // ================= login =================

    public function test_valid_credentials_return_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'token', 'user' => ['id', 'name', 'email', 'roles', 'permissions', 'company', 'employee']]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_the_payload_carries_what_the_app_needs_to_render_itself(): void
    {
        $response = $this->postJson('/api/v1/auth/login', $this->credentials())->assertOk();

        $this->assertSame('Ann Lee', $response->json('user.name'));
        $this->assertSame(['employee'], $response->json('user.roles'));
        // The timezone every displayed time depends on.
        $this->assertSame('America/New_York', $response->json('user.company.timezone'));
        $this->assertSame('E1', $response->json('user.employee.employee_code'));
        $this->assertSame('Ops', $response->json('user.employee.department'));
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']))
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'invalid_credentials']);

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_an_unknown_address_gives_the_same_answer_as_a_wrong_password(): void
    {
        // Saying which was wrong would tell an attacker which addresses exist.
        $wrongPassword = $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']));
        $unknownEmail  = $this->postJson('/api/v1/auth/login', $this->credentials(['email' => 'nobody@acme.test']));

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
    }

    public function test_a_disabled_account_cannot_get_a_token(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertStatus(403)
            ->assertJson(['error' => 'account_disabled']);

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_a_device_name_is_required(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'validation_failed'])
            ->assertJsonPath('errors.device_name.0', 'The device name field is required.');
    }

    public function test_logging_in_twice_from_one_device_replaces_the_token(): void
    {
        $first = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');
        $second = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');

        // A reinstall must not leave a second valid credential behind.
        $this->assertSame(1, $this->user->tokens()->count());
        $this->assertNotSame($first, $second);
    }

    public function test_a_second_device_gets_its_own_token(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials());
        $this->postJson('/api/v1/auth/login', $this->credentials(['device_name' => 'Ann iPad']));

        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_login_is_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']));
        }

        $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']))
            ->assertStatus(429)
            ->assertJson(['ok' => false, 'error' => 'too_many_requests']);
    }

    // ================= identity =================

    public function test_a_token_reaches_me(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ann@acme.test');
    }

    public function test_me_is_closed_without_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'unauthenticated']);
    }

    public function test_a_real_token_works_over_the_wire(): void
    {
        // Not Sanctum::actingAs — this exercises the header the app will send.
        $token = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $this->user->id);
    }

    public function test_a_junk_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);
    }

    // ================= revocation =================

    public function test_logout_revokes_only_this_device(): void
    {
        $phone = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');
        $this->postJson('/api/v1/auth/login', $this->credentials(['device_name' => 'Ann iPad']));

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The iPad is still signed in.
        $this->assertSame(1, $this->user->fresh()->tokens()->count());

        $this->asNewRequest()
            ->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_a_revoked_token_stops_working_everywhere(): void
    {
        $phone = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->asNewRequest()
            ->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/v1/auth/devices')
            ->assertStatus(401);
    }

    public function test_logout_all_revokes_every_device(): void
    {
        $phone = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');
        $this->postJson('/api/v1/auth/login', $this->credentials(['device_name' => 'Ann iPad']));

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('tokens_revoked', 2);

        $this->assertSame(0, $this->user->fresh()->tokens()->count());
    }

    public function test_devices_lists_tokens_and_marks_the_current_one(): void
    {
        $phone = $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');
        $this->postJson('/api/v1/auth/login', $this->credentials(['device_name' => 'Ann iPad']));

        $response = $this->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/v1/auth/devices')
            ->assertOk();

        $devices = collect($response->json('devices'));

        $this->assertCount(2, $devices);
        $this->assertSame('Ann Pixel', $devices->firstWhere('current', true)['name']);
        $this->assertCount(1, $devices->where('current', true));
    }

    // ================= error shape =================

    public function test_every_error_carries_the_same_shape(): void
    {
        // A client that has to parse a different structure per failure mode
        // breaks on the first one it has not seen.
        $unauthenticated = $this->getJson('/api/v1/auth/me');
        $validation      = $this->postJson('/api/v1/auth/login', []);
        $notFound        = $this->getJson('/api/v1/no-such-endpoint');

        foreach ([$unauthenticated, $validation, $notFound] as $response) {
            $response->assertJsonStructure(['ok', 'error', 'message']);
            $this->assertFalse($response->json('ok'));
        }

        $this->assertSame('not_found', $notFound->json('error'));
    }

    public function test_a_web_route_is_untouched_by_the_api_error_shape(): void
    {
        // The handler must only claim api/* — the dashboard still redirects
        // guests to the login page rather than returning JSON.
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_account_with_no_employee_record_still_authenticates(): void
    {
        // Signing in is about the login; only the employee-scoped endpoints
        // need the record, and they say so themselves.
        $orphan = User::create([
            'name' => 'Nobody', 'email' => 'nobody@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $orphan->assignRole('employee');

        $response = $this->postJson('/api/v1/auth/login', $this->credentials(['email' => 'nobody@acme.test']))
            ->assertOk();

        $this->assertNull($response->json('user.employee'));
    }

    // -------------------------------------------------------------------------
    // The security trail (A1.8) — the app is a door like any other
    // -------------------------------------------------------------------------

    /**
     * `AppServiceProvider::recordAuthenticationEvents` hangs the trail off the
     * framework's auth events precisely so that every door is covered, and its
     * own comment names "the mobile API's token endpoint" as one of them. These
     * are the tests that hold it to that — an admin reading the Activity Log
     * after a suspected compromise must not see a clean sheet while the account
     * was being used from a phone.
     */
    public function test_a_sign_in_from_the_app_reaches_the_security_trail(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials())->assertOk();

        $entry = ActivityLog::where('event', ActivityLog::LOGIN)->latest('id')->first();

        $this->assertNotNull($entry, 'a sign-in from the app left no trace in the trail');
        $this->assertSame($this->user->id, $entry->user_id);
        // Which door it was: the trail is read by somebody who needs to know a
        // phone was involved, and a user-agent string is not an answer.
        $this->assertStringContainsString('app', strtolower((string) $entry->description));
    }

    public function test_a_failed_sign_in_from_the_app_is_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'wrong']))
            ->assertStatus(401);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->latest('id')->first();

        $this->assertNotNull($entry, 'a failed app sign-in left no trace');
        // Attributed to the account so "everything about this user" includes
        // attempts on them, with the address that was actually typed.
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('ann@acme.test', $entry->actor_label);
    }

    public function test_an_attempt_on_an_unknown_address_is_recorded_without_a_user(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials(['email' => 'ghost@acme.test']))
            ->assertStatus(401);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->latest('id')->first();

        $this->assertNotNull($entry);
        // The pattern of addresses being tried is what an investigator reads.
        $this->assertNull($entry->user_id);
        $this->assertSame('ghost@acme.test', $entry->actor_label);
    }

    public function test_a_disabled_account_turned_away_is_recorded(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', $this->credentials())->assertStatus(403);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->latest('id')->first();

        // The password was right. Somebody holding working credentials for a
        // switched-off account is the single most interesting line on this
        // screen, and it was previously not written at all.
        $this->assertNotNull($entry, 'a correct password on a disabled account left no trace');
        $this->assertStringContainsString('disabled', strtolower((string) $entry->description));
    }

    public function test_signing_out_one_handset_is_recorded(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'event'   => ActivityLog::LOGOUT,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_signing_out_everywhere_says_how_much_it_revoked(): void
    {
        $this->user->createToken('Ann\'s Pixel');
        $this->user->createToken('Ann\'s iPad');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/logout-all')->assertOk();

        $entry = ActivityLog::where('event', ActivityLog::LOGOUT)
            ->where('user_id', $this->user->id)->latest('id')->first();

        $this->assertNotNull($entry);
        // This is the lost-phone endpoint. An administrator reviewing an
        // incident needs to see that it was used, and how wide it reached —
        // not infer it from tokens that are simply no longer there.
        $this->assertStringContainsString('every', strtolower((string) $entry->description));
    }

    public function test_being_throttled_reaches_the_trail(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']));
        }

        $entry = ActivityLog::where('event', ActivityLog::LOCKOUT)->latest('id')->first();

        // Without this, somebody working through a password list against the
        // app produced a run of failed attempts that stopped dead at five with
        // nothing explaining why — which reads like the attacker gave up rather
        // than like the fence doing its job.
        $this->assertNotNull($entry, 'being throttled left no trace in the trail');
        $this->assertSame('ann@acme.test', $entry->actor_label);
    }

    public function test_the_throttled_refusal_keeps_the_api_error_shape(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']));
        }

        // The limiter supplies this response itself, which bypasses the handler
        // that shapes every other error. This is the pin that stops the two
        // drifting apart quietly.
        $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope']))
            ->assertStatus(429)
            ->assertJsonStructure(['ok', 'error', 'message'])
            ->assertJson(['ok' => false, 'error' => 'too_many_requests']);
    }

    public function test_the_trail_records_the_address_the_request_came_from(): void
    {
        $this->postJson('/api/v1/auth/login', $this->credentials())->assertOk();

        $entry = ActivityLog::where('event', ActivityLog::LOGIN)->latest('id')->first();

        // Meaningless behind a proxy until TRUSTED_PROXIES is set on the box —
        // which is a deployment setting, not a code one — but the column has to
        // be populated for that setting to have anything to correct.
        $this->assertNotNull($entry->ip_address);
    }
}
