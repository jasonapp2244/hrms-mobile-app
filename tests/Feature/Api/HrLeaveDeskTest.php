<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HR's leave desk on the phone (client requirement, 2026-09-22).
 *
 * **The manager inbox and this are two different decisions, and the whole point
 * of this file is that they stay that way.** `Api\LeaveApprovalController`
 * approves by calling `managerApprove()`, which passes a request up and spends
 * nothing; this one calls `approve()`, which commits the days. If a line
 * manager could reach these routes they would be granting company leave without
 * anybody having decided they may.
 *
 * So the gate is tested from both sides: HR gets in, a manager holding
 * `approve-leave` does not, and neither reaches another company's rows.
 *
 * The other half worth the tests is the **balance at the moment of granting**.
 * `approve()` re-checks it and throws, because days can be spent between the
 * request being raised and somebody tapping approve. On the web that throw
 * lands on a form; here it has to come back as something an app can read and
 * show, and the request must be left exactly as it was.
 */
class HrLeaveDeskTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected LeaveType $type;
    protected User $hr;
    protected Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'is_active' => true, 'requires_approval' => true,
        ]);

        $this->hr = $this->account('hr@acme.test', 'hr', $this->company);
        $this->staff = $this->employee('EMP-0001', 'Ann', 'Lee');
    }

    protected function account(string $email, string $role, Company $company): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function employee(string $code, string $first, string $last, ?User $user = null): Employee
    {
        return Employee::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'office_id'     => $this->office->id,
            'user_id'       => $user?->id,
            'employee_code' => $code,
            'first_name'    => $first,
            'last_name'     => $last,
            'status'        => 'active',
        ]);
    }

    /** A request that has cleared the manager step and is sitting with HR. */
    protected function awaitingHr(array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'company_id'          => $this->company->id,
            'employee_id'         => $this->staff->id,
            'leave_type_id'       => $this->type->id,
            'start_date'          => '2026-11-02',
            'end_date'            => '2026-11-03',
            'days'                => 2,
            'status'              => 'pending',
            'manager_approved_at' => now(),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Who may reach the desk
    // -------------------------------------------------------------------------

    public function test_hr_sees_the_queue(): void
    {
        $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('pending.0.employee', 'Ann Lee')
            ->assertJsonPath('pending.0.leave_type', 'Annual Leave');
    }

    public function test_a_line_manager_is_refused_the_hr_desk(): void
    {
        // The test this file exists for. A manager holds `approve-leave` — it
        // is what opens their own inbox — and must not reach the step that
        // spends the days. `manage-leave` is the difference, and it is the same
        // permission the web register is behind.
        $manager = $this->account('lead@acme.test', 'manager', $this->company);
        $this->employee('EMP-0002', 'Mia', 'Manager', $manager);

        $this->assertTrue($manager->can('approve-leave'));
        $this->assertFalse($manager->can('manage-leave'));

        $request = $this->awaitingHr();

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/hr/leave/approvals')->assertForbidden();
        $this->postJson("/api/v1/hr/leave/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/hr/leave/{$request->id}/reject", [
            'decision_note' => 'no',
        ])->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_an_ordinary_employee_is_refused(): void
    {
        $user = $this->account('ann@acme.test', 'employee', $this->company);
        $this->staff->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/hr/leave/approvals')->assertForbidden();
    }

    public function test_the_queue_needs_a_token(): void
    {
        $this->getJson('/api/v1/hr/leave/approvals')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // What the queue contains
    // -------------------------------------------------------------------------

    public function test_a_request_still_with_the_manager_is_not_in_hrs_queue(): void
    {
        // Two steps, two queues. A request nobody has seconded yet is the
        // manager's to answer, and showing it here would let HR decide over
        // the top of a manager who was never asked.
        //
        // The employee needs a line manager for there to be a first step at
        // all: `isAwaitingManager()` is false when `manager_id` is null, and
        // such a request goes straight to HR by design rather than sitting in
        // a queue with no owner. That is the next test.
        $lead = $this->employee('EMP-0002', 'Mia', 'Manager');
        $this->staff->update(['manager_id' => $lead->id]);

        $this->awaitingHr(['manager_approved_at' => null]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending_count', 0);
    }

    public function test_an_employee_with_no_manager_goes_straight_to_hr(): void
    {
        // The other half of the rule above, and the reason it is not simply
        // "manager_approved_at is null". Nobody is above this person, so there
        // is no first step to wait for — HR is the only queue the request can
        // be in.
        $this->assertNull($this->staff->manager_id);

        $this->awaitingHr(['manager_approved_at' => null]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending_count', 1);
    }

    public function test_another_companys_request_is_not_in_the_queue(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirDept = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirOffice = Office::create(['company_id' => $other->id, 'name' => 'Their Office']);
        $theirType = LeaveType::create([
            'company_id' => $other->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);
        $theirStaff = Employee::create([
            'company_id' => $other->id, 'department_id' => $theirDept->id,
            'office_id' => $theirOffice->id, 'employee_code' => 'X-1',
            'first_name' => 'Someone', 'last_name' => 'Else', 'status' => 'active',
        ]);
        $theirs = LeaveRequest::create([
            'company_id' => $other->id, 'employee_id' => $theirStaff->id,
            'leave_type_id' => $theirType->id, 'start_date' => '2026-11-02',
            'end_date' => '2026-11-03', 'days' => 2, 'status' => 'pending',
            'manager_approved_at' => now(),
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending_count', 0)
            ->assertDontSee('Someone');

        // And not by id either — the route takes a bound model, which is the
        // leak the permission alone would not close.
        $this->postJson("/api/v1/hr/leave/{$theirs->id}/approve")->assertForbidden();

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_the_queue_carries_the_balance_and_the_manager_step(): void
    {
        $lead = $this->account('lead@acme.test', 'manager', $this->company);
        $this->awaitingHr(['manager_approved_by' => $lead->id, 'manager_note' => 'Cover arranged.']);

        Sanctum::actingAs($this->hr);

        $response = $this->getJson('/api/v1/hr/leave/approvals')->assertOk();

        // The balance is the number that decides the answer, and the one a
        // phone is most likely to leave out.
        // Read through a cast rather than asserted against a literal: a whole
        // number comes back from JSON as an int, and pinning the type here
        // would be a test about json_encode rather than about the balance.
        $this->assertSame(20.0, (float) $response->json('pending.0.balance.entitled'));
        $this->assertSame(0.0, (float) $response->json('pending.0.balance.used'));

        $response->assertJsonPath('pending.0.balance.capped', true)
            ->assertJsonPath('pending.0.balance.would_exceed', false)
            // Seconded by a manager, rather than arriving unread.
            ->assertJsonPath('pending.0.manager_approved_by', 'Manager')
            ->assertJsonPath('pending.0.manager_note', 'Cover arranged.');
    }

    public function test_the_queue_names_who_else_in_the_department_is_already_off(): void
    {
        $colleague = $this->employee('EMP-0003', 'Sam', 'Cole');

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $colleague->id,
            'leave_type_id' => $this->type->id, 'start_date' => '2026-11-02',
            'end_date' => '2026-11-04', 'days' => 3, 'status' => 'approved',
        ]);

        $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending.0.clashes.0.employee', 'Sam Cole');
    }

    // -------------------------------------------------------------------------
    // Deciding
    // -------------------------------------------------------------------------

    public function test_hr_approval_grants_the_request_and_spends_the_days(): void
    {
        // The difference from the manager inbox, stated as an assertion: this
        // one moves the balance. `managerApprove()` never does.
        $request = $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/hr/leave/{$request->id}/approve", [
            'decision_note' => 'Enjoy it.',
        ])->assertOk()->assertJsonPath('ok', true);

        $fresh = $request->fresh();

        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->hr->id, $fresh->approved_by);
        $this->assertSame('Enjoy it.', $fresh->decision_note);

        $balance = LeaveBalance::where('employee_id', $this->staff->id)
            ->where('leave_type_id', $this->type->id)
            ->first();

        $this->assertSame(2.0, (float) $balance->used_days);
    }

    public function test_hr_rejection_needs_a_reason(): void
    {
        // The employee sees this. A refusal with no words is the one outcome
        // somebody always comes back to ask about.
        $request = $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/hr/leave/{$request->id}/reject", [])
            ->assertStatus(422);

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_hr_rejection_spends_nothing(): void
    {
        $request = $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/hr/leave/{$request->id}/reject", [
            'decision_note' => 'Too many out that week.',
        ])->assertOk();

        $fresh = $request->fresh();

        $this->assertSame('rejected', $fresh->status);

        $balance = LeaveBalance::where('employee_id', $this->staff->id)->first();

        $this->assertTrue($balance === null || (float) $balance->used_days === 0.0);
    }

    public function test_a_request_already_decided_cannot_be_decided_again(): void
    {
        // Two people with the app open, one request. The second tap must be
        // refused with words rather than applied twice.
        $request = $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/hr/leave/{$request->id}/approve")->assertOk();

        $this->postJson("/api/v1/hr/leave/{$request->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'leave_decision_refused');

        $balance = LeaveBalance::where('employee_id', $this->staff->id)->first();

        // Spent once, not twice.
        $this->assertSame(2.0, (float) $balance->used_days);
    }

    public function test_approving_beyond_the_balance_is_refused_in_words(): void
    {
        // The reason `approve()` re-checks rather than trusting submission:
        // the days can be gone by the time somebody taps. On the web that
        // throw lands on a form; here it has to reach the app as a sentence.
        LeaveBalance::create([
            'company_id'    => $this->company->id,
            'employee_id'   => $this->staff->id,
            'leave_type_id' => $this->type->id,
            'year'          => 2026,
            'entitled_days' => 20,
            'used_days'     => 19,
        ]);

        $request = $this->awaitingHr(['days' => 2]);

        Sanctum::actingAs($this->hr);

        $response = $this->postJson("/api/v1/hr/leave/{$request->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'leave_decision_refused');

        $this->assertStringContainsString('only', strtolower($response->json('message')));

        // Left exactly as it was — a refused decision must not half-apply.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(19.0, (float) LeaveBalance::first()->used_days);
    }

    public function test_the_queue_warns_before_the_tap_that_the_balance_would_be_exceeded(): void
    {
        // The same comparison approve() will make, sent up front. Finding out
        // after the tap is the web's behaviour; a phone should say so first.
        LeaveBalance::create([
            'company_id' => $this->company->id, 'employee_id' => $this->staff->id,
            'leave_type_id' => $this->type->id, 'year' => 2026,
            'entitled_days' => 20, 'used_days' => 19,
        ]);

        $this->awaitingHr(['days' => 2]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending.0.balance.would_exceed', true);
    }

    // -------------------------------------------------------------------------
    // The decided list
    // -------------------------------------------------------------------------

    public function test_the_decided_list_answers_what_happened_yesterday(): void
    {
        $request = $this->awaitingHr();

        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/hr/leave/{$request->id}/approve", [
            'decision_note' => 'Fine.',
        ])->assertOk();

        $this->getJson('/api/v1/hr/leave/decided')
            ->assertOk()
            ->assertJsonPath('requests.0.status', 'approved')
            ->assertJsonPath('requests.0.decided_by', 'Hr')
            ->assertJsonPath('requests.0.decision_note', 'Fine.');
    }

    // -------------------------------------------------------------------------
    // What the app is told it may do
    // -------------------------------------------------------------------------

    public function test_the_session_tells_hr_it_may_decide_leave(): void
    {
        // The app must not re-derive this. One rule, stated by the server.
        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.can.decide_leave', true)
            ->assertJsonPath('user.can.view_employees', true)
            ->assertJsonPath('user.can.lead_team', false);
    }

    public function test_the_session_tells_a_manager_it_may_not(): void
    {
        $manager = $this->account('lead@acme.test', 'manager', $this->company);
        $lead = $this->employee('EMP-0002', 'Mia', 'Manager', $manager);
        $this->staff->update(['manager_id' => $lead->id]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.can.lead_team', true)
            ->assertJsonPath('user.can.decide_leave', false)
            ->assertJsonPath('user.can.view_employees', false);
    }

    public function test_the_session_tells_an_ordinary_employee_nothing_extra(): void
    {
        $user = $this->account('ann@acme.test', 'employee', $this->company);
        $this->staff->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.can.lead_team', false)
            ->assertJsonPath('user.can.decide_leave', false)
            ->assertJsonPath('user.can.view_employees', false);
    }
}
