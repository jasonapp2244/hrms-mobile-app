<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The employee's own record, and the two things they may change about it.
 */
class ProfileApiTest extends TestCase
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

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $designation = Designation::create([
            'company_id' => $this->company->id, 'name' => 'Analyst',
        ]);

        $office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ']);

        $boss = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'employee_code' => 'E0', 'first_name' => 'Dana', 'last_name' => 'Roe',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test', 'phone' => '555-0100',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'designation_id' => $designation->id, 'office_id' => $office->id,
            'manager_id' => $boss->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'hire_date' => '2024-03-01', 'work_mode' => 'hybrid', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    // ================= show =================

    public function test_the_profile_carries_the_account_and_the_employee_record(): void
    {
        $response = $this->getJson('/api/v1/profile')->assertOk();

        $this->assertSame('Ann Lee', $response->json('account.name'));
        $this->assertSame('555-0100', $response->json('account.phone'));
        $this->assertSame('E1', $response->json('employee.employee_code'));
        $this->assertSame('Ops', $response->json('employee.department'));
        $this->assertSame('Analyst', $response->json('employee.designation'));
        $this->assertSame('HQ', $response->json('employee.office'));
        $this->assertSame('Dana Roe', $response->json('employee.manager'));
        $this->assertSame('2024-03-01', $response->json('employee.hire_date'));
        $this->assertSame('hybrid', $response->json('employee.work_mode'));
    }

    public function test_the_profile_carries_the_standing_shift(): void
    {
        $response = $this->getJson('/api/v1/profile')->assertOk();

        $this->assertSame('Day', $response->json('shift.name'));
        $this->assertSame('7h 30m', $response->json('shift.working_hours'));
    }

    public function test_the_profile_carries_the_company_timezone(): void
    {
        // Every time the app prints is rendered in it.
        $this->getJson('/api/v1/profile')
            ->assertJsonPath('company.timezone', 'America/New_York');
    }

    public function test_the_password_hash_is_never_returned(): void
    {
        $response = $this->getJson('/api/v1/profile')->assertOk();

        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_an_account_with_no_employee_record_still_gets_its_account(): void
    {
        $orphan = User::create([
            'name' => 'Nobody', 'email' => 'nobody@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        Sanctum::actingAs($orphan);

        $response = $this->getJson('/api/v1/profile')->assertOk();

        $this->assertSame('Nobody', $response->json('account.name'));
        $this->assertNull($response->json('employee'));
        $this->assertNull($response->json('shift'));
    }

    public function test_the_profile_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/profile')->assertStatus(401);
    }

    // ================= update =================

    public function test_contact_details_can_be_corrected(): void
    {
        $this->putJson('/api/v1/profile', [
            'name' => 'Ann Lee-Smith', 'email' => 'ann.smith@acme.test', 'phone' => '555-0199',
        ])->assertOk()->assertJsonPath('account.name', 'Ann Lee-Smith');

        $this->assertSame('ann.smith@acme.test', $this->user->fresh()->email);
    }

    public function test_an_address_already_in_use_is_rejected(): void
    {
        User::create([
            'name' => 'Bob Ray', 'email' => 'bob@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        $this->putJson('/api/v1/profile', [
            'name' => 'Ann Lee', 'email' => 'bob@acme.test',
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    public function test_keeping_your_own_address_is_not_a_clash_with_yourself(): void
    {
        $this->putJson('/api/v1/profile', [
            'name' => 'Ann Lee', 'email' => 'ann@acme.test', 'phone' => '555-0200',
        ])->assertOk();
    }

    public function test_an_employee_cannot_move_their_own_department(): void
    {
        $other = Department::create(['company_id' => $this->company->id, 'name' => 'Finance']);

        $this->putJson('/api/v1/profile', [
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'department_id' => $other->id, 'employee_code' => 'E999',
        ])->assertOk();

        // The org chart is HR's to set. Anything else posted is ignored, not obeyed.
        $this->employee->refresh();
        $this->assertNotSame($other->id, $this->employee->department_id);
        $this->assertSame('E1', $this->employee->employee_code);
    }

    public function test_a_name_is_required(): void
    {
        $this->putJson('/api/v1/profile', ['email' => 'ann@acme.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    // ================= password =================

    public function test_the_password_can_be_changed(): void
    {
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-one',
            'password_confirmation' => 'a-much-longer-one',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-much-longer-one', $this->user->fresh()->password));
    }

    public function test_the_current_password_is_required_even_holding_a_valid_token(): void
    {
        // A phone left unlocked for a minute must not be enough to take the
        // account over.
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'wrong-one',
            'password' => 'a-much-longer-one',
            'password_confirmation' => 'a-much-longer-one',
        ])->assertStatus(422)->assertJsonPath('error', 'wrong_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    public function test_a_mistyped_confirmation_is_refused(): void
    {
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-one',
            'password_confirmation' => 'a-much-longer-two',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_signs_out_the_other_devices(): void
    {
        // Real tokens, not actingAs — the point of the test is what happens to them.
        $phone = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password', 'device_name' => 'Ann Pixel',
        ])->json('token');

        $tablet = $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'password', 'device_name' => 'Ann iPad',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'password',
                'password' => 'a-much-longer-one',
                'password_confirmation' => 'a-much-longer-one',
            ])
            ->assertOk()
            ->assertJsonPath('other_devices_signed_out', 1);

        // The usual reason to change a password is that somebody else may know
        // it — leaving their session alive would defeat the change.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tablet}")
            ->getJson('/api/v1/profile')->assertStatus(401);

        // The device that made the change stays signed in.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/v1/profile')->assertOk();
    }
}
