<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two screens that close Phase 4's hand-editing gaps: the holiday calendar,
 * and HR adjustment of an individual leave balance.
 */
class HolidayAndBalanceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);
    }

    protected function staff(string $role, ?Company $company = null): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => ($company ?? $this->company)->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeEmployee(string $name = 'Ann', string $code = 'E1', ?Company $company = null): Employee
    {
        return Employee::create([
            'company_id' => ($company ?? $this->company)->id,
            'employee_code' => $code, 'first_name' => $name, 'status' => 'active',
        ]);
    }

    // ================= holidays =================

    public function test_admin_can_view_the_holiday_calendar(): void
    {
        $this->actingAs($this->staff('admin'))->get('/holidays')->assertOk();
    }

    public function test_hr_can_view_the_holiday_calendar(): void
    {
        $this->actingAs($this->staff('hr'))->get('/holidays')->assertOk();
    }

    public function test_an_employee_cannot_view_the_holiday_calendar(): void
    {
        $this->actingAs($this->staff('employee'))->get('/holidays')->assertForbidden();
    }

    public function test_a_holiday_can_be_added(): void
    {
        $this->actingAs($this->staff('hr'))
            ->post('/holidays', ['name' => 'Founders Day', 'date' => '2026-08-05', 'is_recurring' => 1])
            ->assertRedirect();

        $this->assertDatabaseHas('holidays', [
            'company_id' => $this->company->id, 'name' => 'Founders Day', 'is_recurring' => true,
        ]);
    }

    public function test_unchecking_recurring_persists(): void
    {
        // The regression this guards: an unchecked box submits nothing, so
        // without the hidden partner the old value would survive the update.
        $holiday = Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Eid', 'date' => '2026-03-20',
            'is_recurring' => true,
        ]);

        $this->actingAs($this->staff('hr'))
            ->put("/holidays/{$holiday->id}", [
                'name' => 'Eid', 'date' => '2026-03-20', 'is_recurring' => 0,
            ])
            ->assertRedirect();

        $this->assertFalse($holiday->fresh()->is_recurring);
    }

    public function test_a_holiday_can_be_removed(): void
    {
        $holiday = Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day', 'date' => '2026-08-05',
        ]);

        $this->actingAs($this->staff('hr'))
            ->delete("/holidays/{$holiday->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
    }

    public function test_another_companys_holiday_is_forbidden(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $holiday = Holiday::create([
            'company_id' => $other->id, 'name' => 'Founders Day', 'date' => '2026-08-05',
        ]);

        $this->actingAs($this->staff('hr'))
            ->delete("/holidays/{$holiday->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('holidays', ['id' => $holiday->id]);
    }

    public function test_a_recurring_holiday_is_listed_in_every_year(): void
    {
        // Stored once against 2020; it must still show when viewing 2026.
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'New Year',
            'date' => '2020-01-01', 'is_recurring' => true,
        ]);

        $this->actingAs($this->staff('hr'))
            ->get('/holidays?year=2026')
            ->assertOk()
            ->assertSee('New Year')
            ->assertSee('Jan 1, 2026');
    }

    public function test_a_one_off_holiday_only_appears_in_its_own_year(): void
    {
        // A name that appears nowhere in the page's own help text, so
        // assertDontSee is testing the listing and not the instructions.
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Spring Festival', 'date' => '2026-03-20',
        ]);

        $this->actingAs($this->staff('hr'))->get('/holidays?year=2026')->assertSee('Spring Festival');
        $this->actingAs($this->staff('hr'))->get('/holidays?year=2027')->assertDontSee('Spring Festival');
    }

    public function test_a_holiday_added_here_changes_what_leave_costs(): void
    {
        // The whole point of the screen: it feeds the leave calculation.
        $employee = $this->makeEmployee();

        $before = app(LeaveService::class)
            ->chargeableDays($this->company, '2026-08-03', '2026-08-07');

        $this->actingAs($this->staff('hr'))
            ->post('/holidays', ['name' => 'Founders Day', 'date' => '2026-08-05']);

        $after = app(LeaveService::class)
            ->chargeableDays($this->company->fresh(), '2026-08-03', '2026-08-07');

        $this->assertSame(5.0, $before);
        $this->assertSame(4.0, $after);
    }

    // ================= balances =================

    public function test_hr_can_view_the_balances_screen(): void
    {
        $this->actingAs($this->staff('hr'))->get('/leave-balances')->assertOk();
    }

    public function test_an_employee_cannot_view_the_balances_screen(): void
    {
        $this->actingAs($this->staff('employee'))->get('/leave-balances')->assertForbidden();
    }

    public function test_provisioning_creates_a_row_per_employee_and_type(): void
    {
        $this->makeEmployee('Ann', 'E1');
        $this->makeEmployee('Bob', 'E2');
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sick Leave', 'days_per_year' => 10,
        ]);

        $this->actingAs($this->staff('hr'))
            ->post('/leave-balances/generate', ['year' => 2026])
            ->assertRedirect();

        // 2 employees x 2 active types.
        $this->assertSame(4, LeaveBalance::where('year', 2026)->count());
        $this->assertSame('20.0', LeaveBalance::where('leave_type_id', $this->type->id)->first()->entitled_days);
    }

    public function test_provisioning_leaves_existing_balances_untouched(): void
    {
        $employee = $this->makeEmployee();
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->type->id,
            'year' => 2026, 'entitled_days' => 5, 'used_days' => 3,
        ]);

        $this->actingAs($this->staff('hr'))->post('/leave-balances/generate', ['year' => 2026]);

        $balance->refresh();
        $this->assertSame('5.0', $balance->entitled_days);
        $this->assertSame('3.0', $balance->used_days);
        $this->assertSame(1, LeaveBalance::count());
    }

    public function test_provisioning_skips_inactive_types(): void
    {
        $this->makeEmployee();
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Retired Leave',
            'days_per_year' => 5, 'is_active' => false,
        ]);

        $this->actingAs($this->staff('hr'))->post('/leave-balances/generate', ['year' => 2026]);

        $this->assertSame(1, LeaveBalance::count());
    }

    public function test_hr_can_adjust_an_entitlement(): void
    {
        $employee = $this->makeEmployee();
        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2026);

        $this->actingAs($this->staff('hr'))
            ->put("/leave-balances/{$balance->id}", [
                'entitled_days' => 10, 'carried_forward' => 2.5, 'used_days' => 0,
            ])
            ->assertRedirect();

        $balance->refresh();
        $this->assertSame('10.0', $balance->entitled_days);
        $this->assertSame('2.5', $balance->carried_forward);
        $this->assertSame(12.5, $balance->available);
    }

    public function test_a_negative_entitlement_is_rejected(): void
    {
        $employee = $this->makeEmployee();
        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2026);

        $this->actingAs($this->staff('hr'))
            ->put("/leave-balances/{$balance->id}", [
                'entitled_days' => -5, 'carried_forward' => 0, 'used_days' => 0,
            ])
            ->assertSessionHasErrors('entitled_days');
    }

    public function test_another_companys_balance_is_forbidden(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $employee = $this->makeEmployee('Zed', 'Z1', $other);
        $type = LeaveType::create([
            'company_id' => $other->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);
        $balance = app(LeaveService::class)->balanceFor($employee, $type, 2026);

        $this->actingAs($this->staff('hr'))
            ->put("/leave-balances/{$balance->id}", [
                'entitled_days' => 99, 'carried_forward' => 0, 'used_days' => 0,
            ])
            ->assertForbidden();

        $this->assertSame('20.0', $balance->fresh()->entitled_days);
    }

    // ---- recalculate ----

    public function test_recalculate_corrects_a_drifted_used_total(): void
    {
        $employee = $this->makeEmployee();
        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2026);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-07', 'days' => 5, 'status' => 'approved',
        ]);

        // Simulates the running total going stale after a direct data fix.
        $balance->update(['used_days' => 99]);

        $this->actingAs($this->staff('hr'))
            ->post("/leave-balances/{$balance->id}/recalculate")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('5.0', $balance->fresh()->used_days);
    }

    public function test_recalculate_ignores_requests_that_were_never_granted(): void
    {
        $employee = $this->makeEmployee();
        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2026);

        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            LeaveRequest::create([
                'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'leave_type_id' => $this->type->id, 'start_date' => '2026-08-03',
                'end_date' => '2026-08-07', 'days' => 5, 'status' => $status,
            ]);
        }

        $this->actingAs($this->staff('hr'))->post("/leave-balances/{$balance->id}/recalculate");

        $this->assertSame('0.0', $balance->fresh()->used_days);
    }

    public function test_recalculate_only_counts_the_balances_own_year(): void
    {
        $employee = $this->makeEmployee();
        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2026);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-07', 'days' => 5, 'status' => 'approved',
        ]);
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->type->id, 'start_date' => '2027-08-02',
            'end_date' => '2027-08-06', 'days' => 5, 'status' => 'approved',
        ]);

        $this->actingAs($this->staff('hr'))->post("/leave-balances/{$balance->id}/recalculate");

        // Leave is charged to the year it starts in, so 2027's does not count here.
        $this->assertSame('5.0', $balance->fresh()->used_days);
    }

    // ================= company scoping =================
    //
    // leave_balances carried a NOT NULL company_id that no migration declared and
    // no code set, so the insert in balanceFor() failed on the deployed database
    // and took the whole leave module down for any year without balances yet.
    // These pin the column to the employee's company on every route in.

    public function test_balance_created_on_first_use_is_stamped_with_the_employees_company(): void
    {
        $employee = $this->makeEmployee();

        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2027);

        $this->assertSame($this->company->id, $balance->company_id);
        $this->assertDatabaseHas('leave_balances', [
            'id' => $balance->id, 'company_id' => $this->company->id,
        ]);
    }

    public function test_a_year_with_no_balances_yet_does_not_fail(): void
    {
        $employee = $this->makeEmployee();

        // The production trigger: the first request of a new leave year, where
        // balanceFor() has to create the row rather than find it.
        $this->assertSame(0, LeaveBalance::where('year', 2028)->count());

        $balance = app(LeaveService::class)->balanceFor($employee, $this->type, 2028);

        $this->assertNotNull($balance->company_id);
        $this->assertSame('20.0', $balance->entitled_days);
    }

    public function test_hr_generate_stamps_the_company_on_every_row_it_creates(): void
    {
        $this->makeEmployee('Ann', 'E1');
        $this->makeEmployee('Bob', 'E2');

        $this->actingAs($this->staff('hr'))
            ->post('/leave-balances/generate', ['year' => 2027])
            ->assertRedirect();

        $rows = LeaveBalance::where('year', 2027)->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($this->company->id, $row->company_id);
        }
    }

    public function test_each_balance_takes_its_own_employees_company_not_the_actors(): void
    {
        $other = Company::create(['name' => 'Other Co', 'timezone' => 'UTC']);
        $theirs = $this->makeEmployee('Zed', 'Z1', $other);

        $balance = app(LeaveService::class)->balanceFor($theirs, $this->type, 2027);

        $this->assertSame($other->id, $balance->company_id);
        $this->assertNotSame($this->company->id, $balance->company_id);
    }
}
