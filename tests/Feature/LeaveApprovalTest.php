<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
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
 * The two-step chain: line manager first, then HR.
 *
 * An employee with no manager skips straight to HR — otherwise their request
 * would sit in a queue nobody owns.
 */
class LeaveApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected const MONDAY = '2026-08-03';
    protected const FRIDAY = '2026-08-07';

    protected Company $company;
    protected LeaveType $type;

    protected User $managerUser;
    protected Employee $manager;
    protected User $staffUser;
    protected Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);

        [$this->managerUser, $this->manager] = $this->makeStaff('Mia', 'M1', ['employee', 'manager']);
        [$this->staffUser, $this->staff] = $this->makeStaff('Sam', 'S1', ['employee']);

        $this->staff->update(['manager_id' => $this->manager->id]);
    }

    /** @return array{0: User, 1: Employee} */
    protected function makeStaff(string $name, string $code, array $roles): array
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $name,
        ]);

        return [$user, $employee];
    }

    protected function officeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeRequest(?Employee $employee = null, array $attrs = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'company_id'    => $this->company->id,
            'employee_id'   => ($employee ?? $this->staff)->id,
            'leave_type_id' => $this->type->id,
            'start_date'    => self::MONDAY,
            'end_date'      => self::FRIDAY,
            'days'          => 5,
            'status'        => 'pending',
        ], $attrs));
    }

    // ---- stage derivation ----

    public function test_a_request_starts_with_the_line_manager(): void
    {
        $request = $this->makeRequest();

        $this->assertTrue($request->isAwaitingManager());
        $this->assertFalse($request->isAwaitingHr());
        $this->assertSame('Awaiting Manager', $request->stage_label);
    }

    public function test_an_employee_with_no_manager_goes_straight_to_hr(): void
    {
        [, $loner] = $this->makeStaff('Lee', 'L1', ['employee']);
        $request = $this->makeRequest($loner);

        $this->assertFalse($request->isAwaitingManager());
        $this->assertTrue($request->isAwaitingHr());
        $this->assertSame('Awaiting HR', $request->stage_label);
    }

    public function test_a_manager_approved_request_moves_to_hr(): void
    {
        $request = $this->makeRequest();
        app(LeaveService::class)->managerApprove($request, $this->managerUser->id, 'cover arranged');

        $request->refresh();
        $this->assertTrue($request->isAwaitingHr());
        // Still pending: the manager step grants nothing on its own.
        $this->assertSame('pending', $request->status);
    }

    // ---- manager inbox access ----

    public function test_a_manager_can_open_the_approvals_inbox(): void
    {
        $this->actingAs($this->managerUser)->get('/employee/approvals')->assertOk();
    }

    public function test_a_plain_employee_cannot_open_the_approvals_inbox(): void
    {
        $this->actingAs($this->staffUser)->get('/employee/approvals')->assertForbidden();
    }

    public function test_the_inbox_lists_only_this_managers_reports(): void
    {
        [, $outsider] = $this->makeStaff('Zed', 'Z1', ['employee']);
        $this->makeRequest();            // Sam — reports to Mia
        $this->makeRequest($outsider);   // Zed — reports to nobody

        $this->actingAs($this->managerUser)
            ->get('/employee/approvals')
            ->assertOk()
            ->assertSee('Sam')
            ->assertDontSee('Zed');
    }

    public function test_the_inbox_warns_about_overlapping_team_leave(): void
    {
        [, $peer] = $this->makeStaff('Pat', 'P1', ['employee']);
        $peer->update(['manager_id' => $this->manager->id]);

        // Already-approved leave for a team mate over the same week.
        $this->makeRequest($peer, ['status' => 'approved']);
        $this->makeRequest();

        $this->actingAs($this->managerUser)
            ->get('/employee/approvals')
            ->assertOk()
            ->assertSee('Also off over these dates');
    }

    // ---- manager decisions ----

    public function test_a_manager_can_pass_a_request_to_hr(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/approve", ['manager_note' => 'fine by me'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->managerUser->id, $request->manager_approved_by);
        $this->assertSame('fine by me', $request->manager_note);
    }

    public function test_the_manager_step_spends_nothing(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->managerUser)->post("/employee/approvals/{$request->id}/approve");

        // The regression this guards: charging at the manager step would take
        // the days twice once HR signs off.
        $this->assertSame(0, LeaveBalance::where('used_days', '>', 0)->count());
    }

    public function test_a_manager_can_reject_with_a_reason(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/reject", ['decision_note' => 'peak season'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame($this->managerUser->id, $request->approved_by);
        $this->assertSame('peak season', $request->decision_note);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/reject", ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_manager_cannot_act_on_someone_elses_report(): void
    {
        [, $outsider] = $this->makeStaff('Zed', 'Z1', ['employee']);
        $request = $this->makeRequest($outsider);

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/approve")
            ->assertForbidden();

        $this->assertNull($request->fresh()->manager_approved_at);
    }

    public function test_a_manager_cannot_approve_their_own_request(): void
    {
        // Mia manages Sam, but nobody manages Mia — her request is HR's to make,
        // and self-approval would be a hole in the chain either way.
        $request = $this->makeRequest($this->manager);

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/approve")
            ->assertForbidden();

        $this->assertNull($request->fresh()->manager_approved_at);
    }

    public function test_a_manager_cannot_approve_the_same_request_twice(): void
    {
        $request = $this->makeRequest();
        $this->actingAs($this->managerUser)->post("/employee/approvals/{$request->id}/approve");

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/approve")
            ->assertSessionHasErrors('status');
    }

    public function test_a_manager_cannot_act_on_an_already_decided_request(): void
    {
        $request = $this->makeRequest(null, ['status' => 'cancelled']);

        $this->actingAs($this->managerUser)
            ->post("/employee/approvals/{$request->id}/approve")
            ->assertSessionHasErrors('status');
    }

    // ---- HR final decision ----

    public function test_hr_approval_grants_the_leave_and_spends_the_balance(): void
    {
        $request = $this->makeRequest();
        app(LeaveService::class)->managerApprove($request, $this->managerUser->id);

        $hr = $this->officeUser('hr');
        $this->actingAs($hr)
            ->post("/leave/{$request->id}/approve", ['decision_note' => 'approved'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($hr->id, $request->approved_by);
        $this->assertSame('5.0', LeaveBalance::first()->used_days);
    }

    public function test_hr_can_override_a_request_still_with_its_manager(): void
    {
        // HR outranks the manager step. The register flags it in the UI, but the
        // action itself is allowed.
        $request = $this->makeRequest();

        $this->actingAs($this->officeUser('hr'))
            ->post("/leave/{$request->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNull($request->fresh()->manager_approved_at);
    }

    public function test_hr_can_reject_with_a_reason(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->officeUser('hr'))
            ->post("/leave/{$request->id}/reject", ['decision_note' => 'not this month'])
            ->assertRedirect();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_admin_can_approve(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->officeUser('admin'))
            ->post("/leave/{$request->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_an_employee_cannot_reach_the_hr_approval_route(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->staffUser)
            ->post("/leave/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_hr_cannot_approve_another_companys_request(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $otherEmployee = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'G1', 'first_name' => 'Zed',
        ]);
        $request = LeaveRequest::create([
            'company_id'    => $other->id,
            'employee_id'   => $otherEmployee->id,
            'leave_type_id' => LeaveType::create([
                'company_id' => $other->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
            ])->id,
            'start_date' => self::MONDAY, 'end_date' => self::FRIDAY, 'days' => 5,
        ]);

        $this->actingAs($this->officeUser('hr'))
            ->post("/leave/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_an_already_decided_request_cannot_be_approved_again(): void
    {
        $request = $this->makeRequest(null, ['status' => 'rejected']);

        $this->actingAs($this->officeUser('hr'))
            ->post("/leave/{$request->id}/approve")
            ->assertSessionHasErrors('status');
    }

    // ---- the balance re-check at the moment of granting ----

    public function test_approval_is_refused_when_the_balance_no_longer_covers_it(): void
    {
        // Both requests fit the 20-day allowance on their own, but not together.
        // The submission check passed for each; only the check at approval time
        // can catch the pair, and without it used_days would exceed the
        // entitlement and the balance would read negative.
        $this->type->update(['days_per_year' => 6]);

        $first  = $this->makeRequest(null, ['days' => 5]);
        $second = $this->makeRequest(null, [
            'days' => 5, 'start_date' => '2026-08-10', 'end_date' => '2026-08-14',
        ]);

        $hr = $this->officeUser('hr');
        $this->actingAs($hr)->post("/leave/{$first->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($hr)->post("/leave/{$second->id}/approve")->assertSessionHasErrors('status');

        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame('5.0', LeaveBalance::first()->used_days);
        $this->assertSame(1.0, LeaveBalance::first()->available);
    }

    public function test_an_uncapped_type_is_not_blocked_by_the_recheck(): void
    {
        $this->type->update(['days_per_year' => 0]);
        $request = $this->makeRequest();

        $this->actingAs($this->officeUser('hr'))
            ->post("/leave/{$request->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $request->fresh()->status);
    }

    // ---- what the employee sees ----

    public function test_the_employee_sees_which_step_their_request_is_on(): void
    {
        $this->makeRequest();

        $this->actingAs($this->staffUser)
            ->get('/employee/leave')
            ->assertOk()
            ->assertSee('Awaiting Manager');
    }

    public function test_the_employee_can_still_withdraw_while_it_is_with_hr(): void
    {
        $request = $this->makeRequest();
        app(LeaveService::class)->managerApprove($request, $this->managerUser->id);

        $this->actingAs($this->staffUser)
            ->post("/employee/leave/{$request->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $request->fresh()->status);
    }
}
