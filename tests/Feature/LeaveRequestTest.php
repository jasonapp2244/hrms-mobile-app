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

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Employee $employee;
    protected User $user;

    /**
     * Fixed dates keep the weekend/holiday maths readable. August 2026 opens on
     * a Saturday, so the 3rd is a Monday and the 8th–9th are the weekend —
     * test_the_calendar_fixture_is_what_it_claims pins that down.
     */
    protected const MONDAY = '2026-08-03';
    protected const FRIDAY = '2026-08-07';
    protected const SATURDAY = '2026-08-08';
    protected const SUNDAY = '2026-08-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Freeze the clock before the fixture dates so they are always "future"
        // leave, whatever day the suite actually runs.
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);

        $this->user = User::create([
            'name' => 'Ann', 'email' => 'ann@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->user->id,
            'employee_code' => 'E1',
            'first_name' => 'Ann',
            'last_name'  => 'Lee',
        ]);
    }

    protected function makeType(array $attrs = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'company_id'    => $this->company->id,
            'name'          => 'Annual Leave',
            'days_per_year' => 20,
        ], $attrs));
    }

    protected function staff(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function apply(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'start_date' => self::MONDAY,
            'end_date'   => self::FRIDAY,
        ], $overrides);

        // Only mint a default type when the test did not bring its own — names
        // are unique per company, so creating one unconditionally would collide.
        $payload['leave_type_id'] ??= $this->makeType()->id;

        return $this->actingAs($this->user)->post('/employee/leave', $payload);
    }

    // ---- fixture sanity ----

    public function test_the_calendar_fixture_is_what_it_claims(): void
    {
        $this->assertSame(Carbon::MONDAY, Carbon::parse(self::MONDAY)->dayOfWeek);
        $this->assertSame(Carbon::FRIDAY, Carbon::parse(self::FRIDAY)->dayOfWeek);
        $this->assertSame(Carbon::SATURDAY, Carbon::parse(self::SATURDAY)->dayOfWeek);
        $this->assertSame(Carbon::SUNDAY, Carbon::parse(self::SUNDAY)->dayOfWeek);
    }

    // ---- access control ----

    public function test_employee_can_view_own_leave_page(): void
    {
        $this->actingAs($this->user)->get('/employee/leave')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/employee/leave')->assertRedirect('/login');
    }

    public function test_admin_cannot_reach_the_employee_leave_portal(): void
    {
        // The portal is scoped to one person's own record; staff use the register.
        $this->actingAs($this->staff('admin'))->get('/employee/leave')->assertForbidden();
    }

    public function test_a_user_without_an_employee_record_is_refused(): void
    {
        $orphan = User::create([
            'name' => 'Nobody', 'email' => 'nobody@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $orphan->assignRole('employee');

        $this->actingAs($orphan)->get('/employee/leave')->assertForbidden();
    }

    // ---- day calculation ----

    public function test_weekends_are_not_charged(): void
    {
        // Monday to Sunday is 7 calendar days but only 5 working ones.
        $this->apply(['end_date' => self::SUNDAY])->assertSessionHasNoErrors();

        $this->assertSame('5.0', LeaveRequest::first()->days);
    }

    public function test_company_holidays_are_not_charged(): void
    {
        Holiday::create([
            'company_id' => $this->company->id,
            'name'       => 'Founders Day',
            'date'       => '2026-08-05', // the Wednesday inside the range
        ]);

        $this->apply()->assertSessionHasNoErrors();

        $this->assertSame('4.0', LeaveRequest::first()->days);
    }

    public function test_a_recurring_holiday_is_charged_in_any_year(): void
    {
        // Stored against 2020 but recurring, so it must still land on the 2026
        // Wednesday inside the range.
        Holiday::create([
            'company_id'   => $this->company->id,
            'name'         => 'Founders Day',
            'date'         => '2020-08-05',
            'is_recurring' => true,
        ]);

        $this->apply()->assertSessionHasNoErrors();

        $this->assertSame('4.0', LeaveRequest::first()->days);
    }

    public function test_a_range_of_only_non_working_days_is_rejected(): void
    {
        $this->apply(['start_date' => self::SATURDAY, 'end_date' => self::SUNDAY])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_custom_company_weekend_is_honoured(): void
    {
        // Friday/Saturday weekend — Sunday becomes a working day.
        $this->company->update(['settings' => ['weekend_days' => [5, 6]]]);

        $days = app(LeaveService::class)->chargeableDays(
            $this->company->fresh(), self::MONDAY, self::SUNDAY
        );

        // Mon–Thu + Sun = 5, with Fri and Sat free.
        $this->assertSame(5.0, $days);
    }

    // ---- submission rules ----

    public function test_a_request_is_created_as_pending(): void
    {
        $this->apply()->assertRedirect('/employee/leave');

        $request = LeaveRequest::first();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->employee->id, $request->employee_id);
        $this->assertSame($this->company->id, $request->company_id);
    }

    public function test_pending_leave_does_not_spend_the_balance_yet(): void
    {
        $this->apply()->assertSessionHasNoErrors();

        $this->assertSame('0.0', LeaveBalance::first()->used_days);
    }

    public function test_overlapping_leave_is_rejected(): void
    {
        $type = $this->makeType();
        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        // Same week again, one day inside the existing range.
        $this->apply(['leave_type_id' => $type->id, 'start_date' => self::FRIDAY, 'end_date' => self::FRIDAY])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_dates_free_again_after_a_cancellation_can_be_rebooked(): void
    {
        $type = $this->makeType();
        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $first = LeaveRequest::first();
        $this->actingAs($this->user)->post("/employee/leave/{$first->id}/cancel");

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $this->assertSame(2, LeaveRequest::count());
    }

    public function test_a_request_larger_than_the_balance_is_rejected(): void
    {
        $type = $this->makeType(['days_per_year' => 2]);

        $this->apply(['leave_type_id' => $type->id])   // 5 working days
            ->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_type_with_no_entitlement_is_treated_as_uncapped(): void
    {
        // How unpaid leave is set up: zero days granted must not mean zero days
        // bookable, or the type could never be used at all.
        $type = $this->makeType(['name' => 'Unpaid Leave', 'days_per_year' => 0, 'is_paid' => false]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_an_inactive_type_cannot_be_booked(): void
    {
        $type = $this->makeType(['is_active' => false]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasErrors('leave_type_id');
    }

    public function test_a_type_from_another_company_cannot_be_booked(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $type  = LeaveType::create([
            'company_id' => $other->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasErrors('leave_type_id');
    }

    // ---- half days ----

    public function test_a_half_day_costs_half_a_day(): void
    {
        $this->apply([
            'start_date'      => self::MONDAY,
            'end_date'        => self::MONDAY,
            'is_half_day'     => 1,
            'half_day_period' => 'second_half',
        ])->assertSessionHasNoErrors();

        $request = LeaveRequest::first();
        $this->assertSame('0.5', $request->days);
        $this->assertSame('second_half', $request->half_day_period);
    }

    public function test_a_half_day_spanning_two_dates_is_rejected(): void
    {
        $this->apply(['is_half_day' => 1])->assertSessionHasErrors('is_half_day');
    }

    public function test_a_half_day_on_a_type_that_forbids_it_is_rejected(): void
    {
        $type = $this->makeType(['allow_half_day' => false]);

        $this->apply([
            'leave_type_id' => $type->id,
            'start_date'    => self::MONDAY,
            'end_date'      => self::MONDAY,
            'is_half_day'   => 1,
        ])->assertSessionHasErrors('is_half_day');
    }

    // ---- auto-approval ----

    public function test_a_type_needing_no_approval_is_granted_immediately(): void
    {
        $type = $this->makeType(['requires_approval' => false]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $request = LeaveRequest::first();
        $this->assertSame('approved', $request->status);
        $this->assertNotNull($request->approved_at);
        // No human approved it, so the approver stays null and the note says why.
        $this->assertNull($request->approved_by);
        $this->assertStringContainsString('Auto-approved', $request->decision_note);
    }

    public function test_approval_spends_the_balance(): void
    {
        $type = $this->makeType(['requires_approval' => false]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $this->assertSame('5.0', LeaveBalance::first()->used_days);
        $this->assertSame(15.0, LeaveBalance::first()->available);
    }

    public function test_the_balance_is_charged_to_the_year_the_leave_starts_in(): void
    {
        $type = $this->makeType(['requires_approval' => false]);

        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $this->assertSame(2026, LeaveBalance::first()->year);
    }

    // ---- cancellation ----

    public function test_cancelling_pending_leave_leaves_the_balance_alone(): void
    {
        $this->apply()->assertSessionHasNoErrors();
        $request = LeaveRequest::first();

        $this->actingAs($this->user)
            ->post("/employee/leave/{$request->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $request->fresh()->status);
        // The regression this guards: crediting days back that were never spent
        // would hand the employee free leave every time they withdrew a request.
        $this->assertSame('0.0', LeaveBalance::first()->used_days);
    }

    public function test_cancelling_approved_future_leave_returns_the_days(): void
    {
        $type = $this->makeType(['requires_approval' => false]);
        $this->apply(['leave_type_id' => $type->id])->assertSessionHasNoErrors();

        $request = LeaveRequest::first();
        $this->assertSame('5.0', LeaveBalance::first()->used_days);

        $this->actingAs($this->user)->post("/employee/leave/{$request->id}/cancel");

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame('0.0', LeaveBalance::first()->used_days);
    }

    public function test_approved_leave_that_has_already_started_cannot_be_withdrawn(): void
    {
        $request = LeaveRequest::create([
            'company_id'    => $this->company->id,
            'employee_id'   => $this->employee->id,
            'leave_type_id' => $this->makeType()->id,
            'start_date'    => '2026-07-13',   // already begun at the frozen clock
            'end_date'      => '2026-07-24',
            'days'          => 10,
            'status'        => 'approved',
        ]);

        $this->actingAs($this->user)
            ->post("/employee/leave/{$request->id}/cancel")
            ->assertSessionHasErrors('status');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_another_employees_request_cannot_be_cancelled(): void
    {
        $otherUser = User::create([
            'name' => 'Bob', 'email' => 'bob@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $otherUser->assignRole('employee');
        $other = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $otherUser->id,
            'employee_code' => 'E2', 'first_name' => 'Bob',
        ]);

        $request = LeaveRequest::create([
            'company_id'    => $this->company->id,
            'employee_id'   => $other->id,
            'leave_type_id' => $this->makeType()->id,
            'start_date'    => self::MONDAY,
            'end_date'      => self::FRIDAY,
            'days'          => 5,
        ]);

        $this->actingAs($this->user)
            ->post("/employee/leave/{$request->id}/cancel")
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    // ---- staff register ----

    public function test_admin_can_view_the_leave_register(): void
    {
        $this->actingAs($this->staff('admin'))->get('/leave')->assertOk();
    }

    public function test_hr_can_view_the_leave_register(): void
    {
        $this->actingAs($this->staff('hr'))->get('/leave')->assertOk();
    }

    public function test_an_employee_cannot_view_the_leave_register(): void
    {
        $this->actingAs($this->user)->get('/leave')->assertForbidden();
    }

    public function test_the_register_lists_requests_and_filters_by_status(): void
    {
        $this->apply()->assertSessionHasNoErrors();

        $this->actingAs($this->staff('hr'))
            ->get('/leave?status=pending')
            ->assertOk()
            ->assertSee('Ann');

        $this->actingAs($this->staff('hr'))
            ->get('/leave?status=approved')
            ->assertOk()
            ->assertSee('No leave requests match these filters.');
    }

    public function test_the_register_does_not_leak_another_companys_leave(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $otherEmployee = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'G1', 'first_name' => 'Zed',
        ]);
        LeaveRequest::create([
            'company_id'    => $other->id,
            'employee_id'   => $otherEmployee->id,
            'leave_type_id' => LeaveType::create([
                'company_id' => $other->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
            ])->id,
            'start_date' => self::MONDAY, 'end_date' => self::FRIDAY, 'days' => 5,
        ]);

        $this->actingAs($this->staff('admin'))
            ->get('/leave')
            ->assertOk()
            ->assertDontSee('Zed');
    }
}
