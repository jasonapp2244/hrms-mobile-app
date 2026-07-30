<?php

namespace Tests\Feature\Api;

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
 * The limits every API caller sits under.
 *
 * Laravel applies no throttle to API routes by default, so this is the
 * difference between "there is a ceiling" and "anyone may hammer any endpoint
 * for ever". The numbers are deliberately generous; what is pinned here is
 * that they are attached at all, and to the right things.
 */
class RateLimitApiTest extends TestCase
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
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ']);
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);
    }

    public function test_every_api_response_carries_its_remaining_allowance(): void
    {
        Sanctum::actingAs($this->user);

        // Without the global limiter these headers are simply absent — which is
        // how an unthrottled API looks from the outside.
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 120);
    }

    public function test_an_endpoint_with_no_limiter_of_its_own_still_has_the_ceiling(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/schedule')->assertOk()->assertHeader('X-RateLimit-Limit', 120);
    }

    public function test_punching_is_held_to_a_tighter_limit_than_reading(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/check')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 20);
    }

    public function test_writing_is_held_to_a_tighter_limit_than_reading(): void
    {
        Sanctum::actingAs($this->user);

        // No interface produces submissions at the read rate; a client stuck
        // retrying one should be slowed rather than left filling the register.
        $this->putJson('/api/v1/profile', ['name' => 'Ann Lee', 'email' => 'ann@acme.test'])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 30);
    }

    public function test_a_punch_loop_is_cut_off(): void
    {
        Sanctum::actingAs($this->user);

        foreach (range(1, 20) as $attempt) {
            $this->postJson('/api/v1/attendance/check');
        }

        $this->postJson('/api/v1/attendance/check')
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_requests');
    }

    public function test_being_throttled_answers_in_the_same_shape_as_any_other_error(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ann@acme.test', 'password' => 'wrong', 'device_name' => 'Phone',
            ]);
        }

        // A client that has to parse a different structure per failure mode
        // breaks on the first one it has not seen.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'wrong', 'device_name' => 'Phone',
        ])
            ->assertStatus(429)
            ->assertJsonStructure(['ok', 'error', 'message'])
            ->assertJsonPath('error', 'too_many_requests');
    }

    public function test_one_persons_failed_logins_do_not_lock_out_a_colleague(): void
    {
        $bob = User::create([
            'name' => 'Bob Ray', 'email' => 'bob@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $bob->assignRole('employee');

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ann@acme.test', 'password' => 'wrong', 'device_name' => 'Phone',
            ]);
        }

        // Ann is throttled; Bob has done nothing wrong. Keying the login limiter
        // on the address alone would let anyone lock a colleague out of the app.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@acme.test', 'password' => 'password', 'device_name' => 'Bob Phone',
        ])->assertOk();
    }

    public function test_one_persons_traffic_does_not_throttle_another(): void
    {
        // A whole office behind one address shares an IP. Limiting on that would
        // have one busy person throttle their colleagues.
        Sanctum::actingAs($this->user);
        $first = $this->getJson('/api/v1/profile')->assertOk();

        $bob = User::create([
            'name' => 'Bob Ray', 'email' => 'bob@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $bob->assignRole('employee');

        Sanctum::actingAs($bob);
        $second = $this->getJson('/api/v1/profile')->assertOk();

        $this->assertSame(
            $first->headers->get('X-RateLimit-Remaining'),
            $second->headers->get('X-RateLimit-Remaining'),
        );
    }

    public function test_the_web_app_is_not_rate_limited_by_the_api_ceiling(): void
    {
        // The limiter is attached to the API group only — a staff member using
        // the dashboard all day must not run into a mobile app's budget.
        $this->get('/login')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
    }
}
