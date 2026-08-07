<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A6.4 accrual and A6.9 carry-forward.
 *
 * Two properties matter more than the arithmetic. Upfront types must behave
 * exactly as they did before accrual existed, or an upgrade silently changes
 * everybody's entitlement. And the year-end roll must be safe to run twice,
 * because it is precisely the job somebody re-runs by hand when they are not
 * sure it worked.
 */
class LeaveAccrualTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected LeaveService $leave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);
        $this->leave = app(LeaveService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function type(array $overrides = []): LeaveType
    {
        static $n = 0;
        $n++;

        return LeaveType::create(array_merge([
            'company_id' => $this->company->id, 'name' => 'Annual ' . $n, 'code' => 'AL' . $n,
            'days_per_year' => 12, 'is_paid' => true, 'is_active' => true,
        ], $overrides));
    }

    private function employee(?string $hireDate = null): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E' . $n, 'first_name' => 'Person', 'last_name' => (string) $n,
            'status' => 'active', 'hire_date' => $hireDate,
        ]);
    }

    // -------------------------------------------------------------------------
    // A6.4 — accrual
    // -------------------------------------------------------------------------

    public function test_an_upfront_type_grants_the_whole_allowance(): void
    {
        // The behaviour every existing type has, and must keep.
        $type = $this->type(['days_per_year' => 20]);
        $employee = $this->employee('2020-01-01');

        $this->assertSame(20.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_a_monthly_type_grants_a_twelfth_per_month(): void
    {
        Carbon::setTestNow('2026-06-15');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2020-01-01');

        // Five completed months plus the one in progress.
        $this->assertSame(6.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_a_starter_accrues_only_from_their_hire_date(): void
    {
        // The whole point: somebody who joins in November cannot book the year.
        Carbon::setTestNow('2026-12-15');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2026-11-01');

        $this->assertSame(2.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_accrual_never_exceeds_the_annual_allowance(): void
    {
        Carbon::setTestNow('2026-12-31');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2015-01-01');

        $this->assertSame(12.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_a_finished_year_has_fully_accrued(): void
    {
        Carbon::setTestNow('2027-03-01');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2020-01-01');

        $this->assertSame(12.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_somebody_hired_after_the_year_ended_accrues_nothing_for_it(): void
    {
        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2027-04-01');

        $this->assertSame(0.0, $this->leave->entitlementFor($employee, $type, 2026));
    }

    public function test_the_command_tops_balances_up(): void
    {
        Carbon::setTestNow('2026-03-15');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2020-01-01');

        $balance = $this->leave->balanceFor($employee, $type, 2026);
        $this->assertSame(3.0, (float) $balance->entitled_days);

        Carbon::setTestNow('2026-06-15');
        $this->artisan('leave:process --year=2026')->assertSuccessful();

        $this->assertSame(6.0, (float) $balance->fresh()->entitled_days);
    }

    public function test_accrual_never_takes_entitlement_back(): void
    {
        // HR adjusts a balance deliberately; a monthly job undoing that would be
        // maddening.
        Carbon::setTestNow('2026-06-15');

        $type = $this->type(['days_per_year' => 12, 'accrual_mode' => 'monthly']);
        $employee = $this->employee('2020-01-01');

        $balance = $this->leave->balanceFor($employee, $type, 2026);
        $balance->update(['entitled_days' => 10]);

        $this->artisan('leave:process --year=2026')->assertSuccessful();

        $this->assertSame(10.0, (float) $balance->fresh()->entitled_days);
    }

    public function test_upfront_types_are_untouched_by_the_accrual_job(): void
    {
        Carbon::setTestNow('2026-02-01');

        $type = $this->type(['days_per_year' => 20]);
        $employee = $this->employee('2020-01-01');
        $balance = $this->leave->balanceFor($employee, $type, 2026);

        $this->artisan('leave:process --year=2026')->assertSuccessful();

        $this->assertSame(20.0, (float) $balance->fresh()->entitled_days);
    }

    // -------------------------------------------------------------------------
    // A6.9 — carry-forward
    // -------------------------------------------------------------------------

    private function balance(Employee $e, LeaveType $t, int $year, float $entitled, float $used, float $carried = 0): LeaveBalance
    {
        return LeaveBalance::create([
            'company_id' => $this->company->id, 'employee_id' => $e->id,
            'leave_type_id' => $t->id, 'year' => $year,
            'entitled_days' => $entitled, 'carried_forward' => $carried, 'used_days' => $used,
        ]);
    }

    public function test_unused_days_carry_into_the_new_year(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 20, 5);

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(15.0, (float) $next->carried_forward);
    }

    public function test_the_carry_is_capped_by_the_type(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => 5]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 20, 2);   // 18 left

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(5.0, (float) $next->carried_forward);
    }

    public function test_a_zero_cap_stops_carry_over_entirely(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => 0]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 20, 0);

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(0.0, (float) $next->carried_forward);
    }

    public function test_an_overdrawn_balance_starts_the_new_year_at_zero_not_in_debt(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 5, 8);   // three days over

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(0.0, (float) $next->carried_forward);
    }

    public function test_running_the_roll_twice_does_not_double_the_carry(): void
    {
        // Exactly the job somebody re-runs by hand when unsure it worked.
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 20, 5);

        $this->leave->carryForward($this->company->id, 2026);
        $this->leave->carryForward($this->company->id, 2026);

        $rows = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(15.0, (float) $rows->first()->carried_forward);
    }

    public function test_the_new_year_also_gets_its_own_entitlement(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => 5]);
        $employee = $this->employee('2020-01-01');

        $this->balance($employee, $type, 2026, 20, 18);   // 2 left

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(20.0, (float) $next->entitled_days);
        $this->assertSame(2.0, (float) $next->carried_forward);
        $this->assertSame(22.0, $next->available);
    }

    public function test_a_leaver_gets_no_new_year_balance(): void
    {
        $type = $this->type(['days_per_year' => 20]);
        $employee = $this->employee('2020-01-01');
        $this->balance($employee, $type, 2026, 20, 0);

        $employee->update(['status' => 'terminated']);

        $this->leave->carryForward($this->company->id, 2026);

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id, 'year' => 2027,
        ]);
    }

    public function test_the_command_rolls_the_year_when_asked(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');
        $this->balance($employee, $type, 2026, 20, 6);

        $this->artisan('leave:process --year=2027 --carry-forward')->assertSuccessful();

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(14.0, (float) $next->carried_forward);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $type = $this->type(['days_per_year' => 20, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');
        $this->balance($employee, $type, 2026, 20, 6);

        $this->artisan('leave:process --year=2027 --carry-forward --dry-run')->assertSuccessful();

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $employee->id, 'year' => 2027,
        ]);
    }

    public function test_carried_days_are_actually_bookable(): void
    {
        // The figure has to reach the balance the booking checks against, or the
        // whole feature is a number on a screen.
        $type = $this->type(['days_per_year' => 0, 'carry_forward_max' => null]);
        $employee = $this->employee('2020-01-01');
        $this->balance($employee, $type, 2026, 10, 7);   // 3 carried

        $this->leave->carryForward($this->company->id, 2026);

        $next = LeaveBalance::where('employee_id', $employee->id)->where('year', 2027)->firstOrFail();

        $this->assertSame(3.0, $next->available);
    }
}
