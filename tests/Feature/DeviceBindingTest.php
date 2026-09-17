<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\TrustedDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One account, one handset (B1.6).
 *
 * The gap this closes is the oldest one in attendance: somebody hands a
 * colleague their password and the colleague clocks them in from the car park.
 * Geofencing does not touch it — the colleague is *at* the office. The B2.7
 * flags do not either; nothing is being spoofed. The only thing that separates
 * the two is which phone the sign-in came from.
 *
 * Four properties are tested hardest, and three of them are about *not*
 * refusing people:
 *
 *  - **Off until somebody turns it on.** A control that can refuse a sign-in
 *    must never arrive switched on.
 *  - **Nobody is locked out on the day it is switched on.** Each account claims
 *    its own phone at its next sign-in; the control bites from the second.
 *  - **An older app is let through.** It sends no device id — that is a build,
 *    not an impostor, and locking out a rollout is how a security feature gets
 *    switched off for good.
 *  - And the one refusal that matters: a *second* phone, with the right
 *    password.
 */
class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $department = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'user_id' => $this->user->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    private function bindingOn(): void
    {
        $this->company->update([
            'settings' => array_merge($this->company->settings ?? [], [
                'enforce_device_binding' => true,
            ]),
        ]);
        $this->company->refresh();
    }

    private function signIn(?string $deviceId, string $name = 'Ann\'s Pixel')
    {
        return $this->postJson('/api/v1/auth/login', array_filter([
            'email'       => 'ann@acme.test',
            'password'    => 'password',
            'device_name' => $name,
            'device_id'   => $deviceId,
            'platform'    => 'android',
        ], fn ($v) => $v !== null));
    }

    // ================= off by default =================

    public function test_binding_is_off_until_somebody_turns_it_on(): void
    {
        $this->assertFalse((bool) $this->company->policy('enforce_device_binding'));

        $this->signIn('phone-one')->assertOk();
        $this->signIn('phone-two')->assertOk();

        // Nothing recorded either: a feature that is off should leave no trace
        // for somebody to wonder about later.
        $this->assertSame(0, TrustedDevice::count());
    }

    // ================= the rollout =================

    public function test_the_first_handset_claims_the_account_and_is_let_in(): void
    {
        $this->bindingOn();

        $this->signIn('phone-one')->assertOk()->assertJsonStructure(['token']);

        $device = TrustedDevice::sole();

        $this->assertSame($this->user->id, $device->user_id);
        $this->assertSame('phone-one', $device->device_id);
        $this->assertSame("Ann's Pixel", $device->device_name);
        $this->assertNotNull($device->trusted_at);
        $this->assertNull($device->released_at);
    }

    public function test_the_same_handset_signs_in_again_without_a_second_row(): void
    {
        $this->bindingOn();

        $this->signIn('phone-one')->assertOk();
        $this->signIn('phone-one')->assertOk();

        $this->assertSame(1, TrustedDevice::count());
        $this->assertNotNull(TrustedDevice::sole()->last_seen_at);
    }

    public function test_an_app_that_sends_no_device_id_is_let_through(): void
    {
        $this->bindingOn();

        // An older build, not an impostor. Refusing it would lock out everybody
        // mid-rollout, which is how a security control gets switched back off —
        // and it buys nothing, because that build cannot be bound either.
        $this->signIn(null)->assertOk();

        $this->assertSame(0, TrustedDevice::count());
    }

    // ================= the refusal =================

    public function test_a_second_handset_with_the_right_password_is_refused(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();

        $response = $this->signIn('phone-two', 'Bob\'s phone')
            ->assertStatus(403)
            ->assertJsonPath('error', 'device_not_trusted');

        // **No token.** The refusal is worthless if it hands out the credential
        // anyway, and this is the assertion that would catch a future refactor
        // moving the check below `createToken`.
        $this->assertArrayNotHasKey('token', $response->json());

        // And it did not quietly bind the second phone on the way past.
        $this->assertSame(1, TrustedDevice::count());
        $this->assertSame('phone-one', TrustedDevice::sole()->device_id);
    }

    public function test_the_refusal_reaches_the_security_trail(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();
        $this->signIn('phone-two', 'Bob\'s phone')->assertStatus(403);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->latest('id')->firstOrFail();

        // The line a person reads after a dispute has to say *which* phone and
        // *whose* account, because a shared password and a stolen one look
        // identical from here and only a human can tell them apart.
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertStringContainsString("Bob's phone", $entry->description);
        $this->assertStringContainsString('untrusted', $entry->description);
    }

    public function test_a_wrong_password_never_mentions_devices(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'wrong',
            'device_name' => 'Bob\'s phone', 'device_id' => 'phone-two',
        ])->assertStatus(401);

        // Order matters: the password is checked first, so somebody guessing
        // passwords is never told that the account exists *and* is bound to a
        // phone — which is two facts more than they had.
        $this->assertSame('invalid_credentials', $response->json('error'));
    }

    // ================= the release =================

    public function test_releasing_lets_a_new_handset_claim_the_account(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();

        $device = TrustedDevice::sole();

        $this->actingAs($this->hr)
            ->post(route('devices.release', $device))
            ->assertRedirect();

        // Kept, not deleted: who was trusted and when that stopped is what
        // somebody asks after a dispute, and a deleted row answers neither.
        $device->refresh();
        $this->assertNotNull($device->released_at);
        $this->assertSame($this->hr->id, $device->released_by_user_id);

        // The new phone now claims it, and the history survives beside it.
        $this->signIn('phone-two', 'Ann\'s new phone')->assertOk();

        $this->assertSame(2, TrustedDevice::count());
        $this->assertSame(1, TrustedDevice::query()->active()->count());
        $this->assertSame('phone-two', TrustedDevice::query()->active()->sole()->device_id);
    }

    public function test_releasing_twice_is_refused_rather_than_silently_rewriting_history(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();

        $device = TrustedDevice::sole();

        $this->actingAs($this->hr)->post(route('devices.release', $device));
        $releasedAt = $device->refresh()->released_at;

        $this->actingAs($this->hr)->post(route('devices.release', $device))
            ->assertSessionHas('error');

        // The first release's timestamp and actor stand. Overwriting them would
        // lose who actually did it.
        $this->assertEquals($releasedAt, $device->refresh()->released_at);
    }

    public function test_another_companys_device_cannot_be_released(): void
    {
        $other = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirUser = User::create([
            'name' => 'Zed Other', 'email' => 'zed@rival.test',
            'password' => Hash::make('password'), 'company_id' => $other->id,
        ]);

        $theirs = TrustedDevice::create([
            'company_id' => $other->id, 'user_id' => $theirUser->id,
            'device_id' => 'their-phone', 'trusted_at' => now(),
        ]);

        // The route takes a bound model, which is the leak this checks for.
        $this->actingAs($this->hr)
            ->post(route('devices.release', $theirs))
            ->assertForbidden();

        $this->assertNull($theirs->refresh()->released_at);
    }

    public function test_the_list_shows_this_companys_bound_phones_and_hides_released_ones(): void
    {
        $this->bindingOn();
        $this->signIn('phone-one')->assertOk();

        $this->actingAs($this->hr)->get(route('devices.index'))
            ->assertOk()
            ->assertSee("Ann's Pixel");

        $this->actingAs($this->hr)->post(route('devices.release', TrustedDevice::sole()));

        $this->actingAs($this->hr)->get(route('devices.index'))
            ->assertOk()
            ->assertDontSee("Ann's Pixel");

        $this->actingAs($this->hr)->get(route('devices.index', ['show_released' => 1]))
            ->assertOk()
            ->assertSee("Ann's Pixel");
    }

    public function test_an_ordinary_employee_cannot_reach_the_list(): void
    {
        $this->actingAs($this->user)->get(route('devices.index'))->assertForbidden();
    }
}
