<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
    }

    protected function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::create([
            'name'       => ucfirst($role),
            'email'      => $role . uniqid() . '@test.local',
            'password'   => 'password',
            'company_id' => ($company ?? $this->company)->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeType(array $attrs = [], ?Company $company = null): LeaveType
    {
        return LeaveType::create(array_merge([
            'company_id'    => ($company ?? $this->company)->id,
            'name'          => 'Annual Leave',
            'days_per_year' => 20,
        ], $attrs));
    }

    // ---- access control ----

    public function test_admin_can_view_leave_types(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/leave-types')
            ->assertOk();
    }

    public function test_hr_can_view_leave_types(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get('/leave-types')
            ->assertOk();
    }

    public function test_employee_cannot_view_leave_types(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/leave-types')
            ->assertForbidden();
    }

    public function test_manager_cannot_view_leave_types(): void
    {
        // Managers approve leave; they do not configure the types.
        $this->actingAs($this->userWithRole('manager'))
            ->get('/leave-types')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/leave-types')->assertRedirect('/login');
    }

    // ---- create / update ----

    public function test_admin_can_create_a_leave_type(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->post('/leave-types', [
                'name'              => 'Sick Leave',
                'code'              => 'SL',
                'days_per_year'     => 10,
                'is_paid'           => 1,
                'requires_approval' => 1,
                'allow_half_day'    => 1,
                'is_active'         => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leave_types', [
            'company_id' => $this->company->id,
            'name'       => 'Sick Leave',
            'is_paid'    => true,
        ]);
    }

    public function test_duplicate_name_within_a_company_is_rejected(): void
    {
        $this->makeType(['name' => 'Annual Leave']);

        $this->actingAs($this->userWithRole('admin'))
            ->post('/leave-types', ['name' => 'Annual Leave', 'days_per_year' => 5])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, LeaveType::where('name', 'Annual Leave')->count());
    }

    public function test_the_same_name_is_allowed_in_a_different_company(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $this->makeType(['name' => 'Annual Leave'], $other);

        $this->actingAs($this->userWithRole('admin'))
            ->post('/leave-types', ['name' => 'Annual Leave', 'days_per_year' => 20])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, LeaveType::where('name', 'Annual Leave')->count());
    }

    public function test_unchecking_a_flag_persists(): void
    {
        // The regression this guards: an unchecked box submits nothing, so
        // without the hidden partner the old value would survive the update.
        $type = $this->makeType(['is_paid' => true, 'allow_half_day' => true]);

        $this->actingAs($this->userWithRole('admin'))
            ->put("/leave-types/{$type->id}", [
                'name'          => $type->name,
                'days_per_year' => 20,
                'is_paid'       => 0,
                'allow_half_day' => 0,
                'is_active'     => 1,
            ])
            ->assertRedirect();

        $type->refresh();
        $this->assertFalse($type->is_paid);
        $this->assertFalse($type->allow_half_day);
        $this->assertTrue($type->is_active);
    }

    public function test_empty_carry_forward_is_stored_as_unlimited(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->post('/leave-types', [
                'name'              => 'Unpaid Leave',
                'days_per_year'     => 0,
                'carry_forward_max' => '',
            ])
            ->assertRedirect();

        $this->assertNull(LeaveType::where('name', 'Unpaid Leave')->first()->carry_forward_max);
    }

    public function test_zero_carry_forward_is_kept_distinct_from_unlimited(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->post('/leave-types', [
                'name'              => 'Casual Leave',
                'days_per_year'     => 5,
                'carry_forward_max' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('0.0', LeaveType::where('name', 'Casual Leave')->first()->carry_forward_max);
    }

    // ---- delete ----

    public function test_a_leave_type_in_use_cannot_be_deleted(): void
    {
        $type     = $this->makeType();
        $employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'E1', 'first_name' => 'Ann',
        ]);
        LeaveRequest::create([
            'company_id'    => $this->company->id,
            'employee_id'   => $employee->id,
            'leave_type_id' => $type->id,
            'start_date'    => '2026-08-01',
            'end_date'      => '2026-08-02',
            'days'          => 2,
        ]);

        $this->actingAs($this->userWithRole('admin'))
            ->delete("/leave-types/{$type->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('leave_types', ['id' => $type->id]);
    }

    public function test_an_unused_leave_type_can_be_deleted(): void
    {
        $type = $this->makeType();

        $this->actingAs($this->userWithRole('admin'))
            ->delete("/leave-types/{$type->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('leave_types', ['id' => $type->id]);
    }

    public function test_a_leave_type_from_another_company_is_forbidden(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $type  = $this->makeType([], $other);

        $this->actingAs($this->userWithRole('admin'))
            ->delete("/leave-types/{$type->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('leave_types', ['id' => $type->id]);
    }
}
