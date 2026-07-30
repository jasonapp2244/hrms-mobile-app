<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\PushDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registering a handset for push notifications.
 *
 * Nothing is delivered yet — Phase 5 owns that. What is pinned here is the
 * address book being correct, because the failure mode is notifications going
 * to the wrong person's phone.
 */
class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->user = $this->account('ann@acme.test', 'Ann Lee');

        Sanctum::actingAs($this->user);
    }

    protected function account(string $email, string $name): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        return $user;
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'token'       => 'fcm-token-abc',
            'platform'    => 'android',
            'device_name' => 'Ann Pixel',
            'app_version' => '1.0.0',
        ], $overrides);
    }

    // ================= registering =================

    public function test_a_handset_can_register(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())
            ->assertOk()
            ->assertJsonPath('device.platform', 'android')
            ->assertJsonPath('device.device_name', 'Ann Pixel');

        $this->assertSame(1, $this->user->pushDevices()->count());
    }

    public function test_registering_twice_does_not_duplicate_the_handset(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        // The app re-registers on every launch. A second row would mean every
        // notification delivered twice.
        $this->assertSame(1, PushDevice::count());
    }

    public function test_re_registering_refreshes_when_it_was_last_seen(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        PushDevice::first()->update(['last_seen_at' => now()->subMonths(4)]);

        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        // Stale tokens are prunable precisely because this is kept current.
        $this->assertTrue(PushDevice::first()->last_seen_at->isToday());
    }

    public function test_a_new_app_version_is_recorded_on_the_same_handset(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        $this->postJson('/api/v1/devices', $this->payload(['app_version' => '1.4.0']))->assertOk();

        $this->assertSame(1, PushDevice::count());
        $this->assertSame('1.4.0', PushDevice::first()->app_version);
    }

    public function test_a_handed_on_phone_moves_to_its_new_owner(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        $bob = $this->account('bob@acme.test', 'Bob Ray');
        Sanctum::actingAs($bob);
        $this->postJson('/api/v1/devices', $this->payload(['device_name' => 'Bob Pixel']))->assertOk();

        // The token belongs to the installation, not the person. Leaving it with
        // Ann would send Bob's leave approvals to her phone.
        $this->assertSame(1, PushDevice::count());
        $this->assertSame($bob->id, PushDevice::first()->user_id);
        $this->assertSame(0, $this->user->pushDevices()->count());
    }

    public function test_one_person_may_have_several_handsets(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        $this->postJson('/api/v1/devices', $this->payload([
            'token' => 'apns-token-xyz', 'platform' => 'ios', 'device_name' => 'Ann iPad',
        ]))->assertOk();

        $this->assertSame(2, $this->user->pushDevices()->count());
    }

    public function test_a_token_is_required(): void
    {
        $this->postJson('/api/v1/devices', $this->payload(['token' => '']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_an_unknown_platform_is_refused(): void
    {
        $this->postJson('/api/v1/devices', $this->payload(['platform' => 'symbian']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['platform']]);
    }

    public function test_registering_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/v1/devices', $this->payload())
            ->assertStatus(401);
    }

    // ================= listing =================

    public function test_devices_can_be_listed(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        $this->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonPath('devices.0.device_name', 'Ann Pixel')
            ->assertJsonPath('devices.0.platform', 'android');
    }

    public function test_the_push_token_is_never_listed_back(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        // It is a credential for sending to that handset. Listing it would put
        // every one of the person's devices in a payload any of them can read.
        $this->getJson('/api/v1/devices')
            ->assertOk()
            ->assertDontSee('fcm-token-abc');
    }

    public function test_only_the_callers_own_handsets_are_listed(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        Sanctum::actingAs($this->account('bob@acme.test', 'Bob Ray'));

        $this->getJson('/api/v1/devices')->assertOk()->assertJsonCount(0, 'devices');
    }

    // ================= unregistering =================

    public function test_a_handset_can_be_unregistered(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        $this->deleteJson('/api/v1/devices', ['token' => 'fcm-token-abc'])
            ->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertSame(0, PushDevice::count());
    }

    public function test_unregistering_something_unknown_is_not_an_error(): void
    {
        // The caller wanted it gone, and it is. Failing here would leave an app
        // retrying a cleanup it can never complete.
        $this->deleteJson('/api/v1/devices', ['token' => 'never-registered'])
            ->assertOk()
            ->assertJsonPath('removed', 0);
    }

    public function test_one_person_cannot_silence_anothers_phone(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        Sanctum::actingAs($this->account('bob@acme.test', 'Bob Ray'));
        $this->deleteJson('/api/v1/devices', ['token' => 'fcm-token-abc'])
            ->assertOk()
            ->assertJsonPath('removed', 0);

        $this->assertSame(1, PushDevice::count());
    }

    // ================= signing out =================

    public function test_signing_out_stops_the_notifications_for_that_handset(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password', 'device_name' => 'Ann Pixel',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices', $this->payload())->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout', ['push_token' => 'fcm-token-abc'])
            ->assertOk();

        // A phone still receiving somebody's approvals after they signed out is
        // a leak, not a loose end.
        $this->assertSame(0, PushDevice::count());
    }

    public function test_signing_out_leaves_the_other_handsets_alone(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        $this->postJson('/api/v1/devices', $this->payload([
            'token' => 'apns-token-xyz', 'platform' => 'ios',
        ]))->assertOk();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password', 'device_name' => 'Ann Pixel',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout', ['push_token' => 'fcm-token-abc'])
            ->assertOk();

        $this->assertSame(1, PushDevice::count());
        $this->assertSame('apns-token-xyz', PushDevice::first()->token);
    }

    public function test_signing_out_everywhere_removes_every_handset(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();
        $this->postJson('/api/v1/devices', $this->payload([
            'token' => 'apns-token-xyz', 'platform' => 'ios',
        ]))->assertOk();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password', 'device_name' => 'Ann Pixel',
        ])->json('token');

        // This is the endpoint for a phone that is gone — it has to stop
        // pushing to it as well as stop it reading.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('devices_removed', 2);

        $this->assertSame(0, PushDevice::count());
    }

    public function test_deleting_an_account_takes_its_handsets_with_it(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertOk();

        $this->user->delete();

        $this->assertSame(0, PushDevice::count());
    }
}
