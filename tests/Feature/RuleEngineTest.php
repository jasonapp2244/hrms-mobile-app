<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\PolicyRule;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PolicyRuleFired;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A2.9 / A6.6 — the conditional rule engine.
 *
 * Everything else on the Policies screen is one value for the whole company.
 * This is the part that can say "when somebody in the Croydon depot clocks in
 * more than twenty minutes late, tell their manager", which no setting could.
 *
 * What is worth testing hardest here is not that a matching rule fires — that
 * is the easy half — but the three ways this could hurt a working system:
 *
 *  - **a rule must never break a punch.** The engine runs inside the path that
 *    records attendance, and a bad rule row, a deleted leave type or a
 *    notification failure must cost the rule, not the clock-in;
 *  - **conditions are ANDed**, so a rule with two conditions must not fire on
 *    one of them. A rule that fires too widely is worse than none: it teaches
 *    people to ignore the notification;
 *  - **a rule belongs to one company**, like every other row on this box.
 */
class RuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $shift;
    protected Employee $employee;
    protected User $manager;
    protected User $hr;

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

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $this->manager = $this->makeUser('Mia Manager', 'mia@acme.test', 'manager');
        $this->hr = $this->makeUser('Hal HR', 'hal@acme.test', 'hr');

        $managerEmployee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $this->manager->id,
            'employee_code' => 'EMP-0009', 'first_name' => 'Mia', 'last_name' => 'Manager',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $this->makeUser('Ann Lee', 'ann@acme.test', 'employee')->id,
            'employee_code' => 'EMP-0001', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'manager_id' => $managerEmployee->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function rule(array $attributes = []): PolicyRule
    {
        return PolicyRule::create(array_merge([
            'company_id' => $this->company->id,
            'name'       => 'Late arrivals',
            'trigger'    => PolicyRule::TRIGGER_PUNCH,
            'conditions' => [['field' => 'status', 'operator' => 'is', 'value' => 'late']],
            'actions'    => [['type' => PolicyRule::ACTION_LOG]],
            'is_active'  => true,
        ], $attributes));
    }

    /** Punch at a wall-clock time, which is what runs the punch rules. */
    private function punchAt(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'UTC'));

        app(AttendanceService::class)->record($this->employee->fresh(), $this->office);
    }

    // -------------------------------------------------------------------------
    // Matching
    // -------------------------------------------------------------------------

    public function test_a_matching_rule_fires(): void
    {
        $rule = $this->rule();

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNotNull($rule->fresh()->last_fired_at);
    }

    public function test_a_rule_that_does_not_match_stays_quiet(): void
    {
        $rule = $this->rule();

        // Inside the grace window, so the punch is ontime and the rule asks
        // about late.
        $this->punchAt('2026-04-06 09:10:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_every_condition_has_to_match_not_just_one(): void
    {
        // The rule this codebase would most likely get wrong: two conditions,
        // one of them true. ORing them here would notify a manager about every
        // punch in the department, which is the failure that teaches people to
        // ignore the notification.
        $rule = $this->rule(['conditions' => [
            ['field' => 'status', 'operator' => 'is', 'value' => 'late'],
            ['field' => 'source', 'operator' => 'is', 'value' => 'kiosk'],
        ]]);

        // Late, but recorded from the browser rather than a kiosk.
        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_a_rule_with_no_conditions_fires_on_every_event(): void
    {
        // "every time" is a legible thing to want — a department that wants
        // every punch logged — and an empty AND is true.
        $rule = $this->rule(['conditions' => []]);

        $this->punchAt('2026-04-06 09:10:00');

        $this->assertNotNull($rule->fresh()->last_fired_at);
    }

    public function test_at_least_compares_numbers_as_numbers(): void
    {
        // The value arrives out of a JSON column as a string. "9" >= "20" is
        // true as a string comparison and false as the arithmetic anybody
        // writing this rule meant.
        $rule = $this->rule(['conditions' => [
            ['field' => 'minutes_late', 'operator' => 'at_least', 'value' => '20'],
        ]]);

        // 09:24 is nine minutes past the 09:15 grace window.
        $this->punchAt('2026-04-06 09:24:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_at_least_fires_once_the_number_is_reached(): void
    {
        $rule = $this->rule(['conditions' => [
            ['field' => 'minutes_late', 'operator' => 'at_least', 'value' => '20'],
        ]]);

        // 09:40 is twenty-five minutes past the grace window.
        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNotNull($rule->fresh()->last_fired_at);
    }

    public function test_an_inactive_rule_never_runs(): void
    {
        $rule = $this->rule(['is_active' => false]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_a_rule_on_another_trigger_is_not_consulted(): void
    {
        $rule = $this->rule(['trigger' => PolicyRule::TRIGGER_LEAVE, 'conditions' => []]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_another_companys_rule_does_not_fire_here(): void
    {
        $other = Company::create(['name' => 'Beta', 'timezone' => 'UTC', 'currency' => 'USD']);

        $rule = $this->rule(['company_id' => $other->id, 'conditions' => []]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function test_the_log_action_writes_to_the_activity_trail(): void
    {
        $this->rule(['name' => 'Late arrivals', 'actions' => [['type' => PolicyRule::ACTION_LOG]]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertDatabaseHas('activity_logs', ['event' => ActivityLog::RULE_FIRED]);
    }

    public function test_the_manager_action_notifies_the_employees_own_manager(): void
    {
        $this->rule(['actions' => [['type' => PolicyRule::ACTION_NOTIFY_MANAGER]]]);

        $this->punchAt('2026-04-06 09:40:00');

        Notification::assertSentTo($this->manager, \App\Notifications\PolicyRuleFired::class);
    }

    public function test_the_role_action_notifies_that_role(): void
    {
        $this->rule(['actions' => [
            ['type' => PolicyRule::ACTION_NOTIFY_ROLE, 'role' => 'hr'],
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        Notification::assertSentTo($this->hr, \App\Notifications\PolicyRuleFired::class);
        Notification::assertNotSentTo($this->manager, \App\Notifications\PolicyRuleFired::class);
    }

    public function test_a_role_notification_does_not_reach_another_company(): void
    {
        $other = Company::create(['name' => 'Beta', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherHr = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta.test',
            'password' => Hash::make('password'), 'company_id' => $other->id,
        ]);
        $otherHr->assignRole('hr');

        $this->rule(['actions' => [
            ['type' => PolicyRule::ACTION_NOTIFY_ROLE, 'role' => 'hr'],
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        Notification::assertNotSentTo($otherHr, \App\Notifications\PolicyRuleFired::class);
    }

    public function test_every_action_on_a_rule_runs(): void
    {
        $this->rule(['actions' => [
            ['type' => PolicyRule::ACTION_LOG],
            ['type' => PolicyRule::ACTION_NOTIFY_MANAGER],
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertDatabaseHas('activity_logs', ['event' => ActivityLog::RULE_FIRED]);
        Notification::assertSentTo($this->manager, \App\Notifications\PolicyRuleFired::class);
    }

    // -------------------------------------------------------------------------
    // Nothing a rule does may cost a punch
    // -------------------------------------------------------------------------

    public function test_a_condition_naming_a_field_the_code_no_longer_has_is_skipped(): void
    {
        // A rule written against a version that had this field, read back by
        // one that does not. It must not fire — the condition cannot be shown
        // to be true — and it must not throw.
        $rule = $this->rule(['conditions' => [
            ['field' => 'phase_of_the_moon', 'operator' => 'is', 'value' => 'waxing'],
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertNull($rule->fresh()->last_fired_at);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_an_action_the_code_no_longer_has_does_not_stop_the_others(): void
    {
        $this->rule(['actions' => [
            ['type' => 'send_a_pigeon'],
            ['type' => PolicyRule::ACTION_LOG],
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertDatabaseHas('activity_logs', ['event' => ActivityLog::RULE_FIRED]);
    }

    public function test_the_punch_is_still_recorded_when_a_rule_throws(): void
    {
        // The whole reason the engine is wrapped. An employee standing at a
        // kiosk must not be refused because somebody wrote a bad rule.
        $this->rule(['actions' => [
            ['type' => PolicyRule::ACTION_NOTIFY_ROLE],  // no role named
        ]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame('late', AttendanceLog::value('status'));
    }

    public function test_a_rule_with_no_manager_to_notify_is_not_an_error(): void
    {
        // Managers have no manager. The action has nobody to address and that
        // is an ordinary outcome, not a failure.
        $this->employee->update(['manager_id' => null]);

        $this->rule(['actions' => [['type' => PolicyRule::ACTION_NOTIFY_MANAGER]]]);

        $this->punchAt('2026-04-06 09:40:00');

        $this->assertDatabaseCount('attendance_logs', 1);
        Notification::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // The leave trigger
    //
    // Its own section because the leave half shipped with the call in place and
    // the method behind it missing — every leave request raised on that build
    // died on `Call to undefined method runLeaveRules()`, which no punch test
    // could have caught. The first test below is the one that would have.
    // -------------------------------------------------------------------------

    /** @return array{0: LeaveType, 1: LeaveRequest} */
    private function requestLeave(string $start, ?string $end = null, array $typeAttributes = []): array
    {
        $type = LeaveType::create(array_merge([
            'company_id'        => $this->company->id,
            'name'              => 'Annual Leave',
            'days_per_year'     => 20,
            'is_active'         => true,
            'requires_approval' => true,
        ], $typeAttributes));

        $request = app(LeaveService::class)->submit($this->employee->fresh(), [
            'leave_type_id' => $type->id,
            'start_date'    => $start,
            'end_date'      => $end ?? $start,
        ]);

        return [$type, $request];
    }

    public function test_a_leave_request_can_be_raised_at_all(): void
    {
        // Not a rules test so much as the floor underneath them: the engine is
        // called from inside submit(), so anything wrong on that path costs the
        // request rather than the rule.
        [, $request] = $this->requestLeave('2026-05-04');

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertSame('pending', $request->status);
    }

    public function test_a_leave_rule_fires_on_a_request(): void
    {
        $rule = $this->rule([
            'trigger'    => PolicyRule::TRIGGER_LEAVE,
            'name'       => 'Any leave',
            'conditions' => [],
            'actions'    => [['type' => PolicyRule::ACTION_LOG]],
        ]);

        $this->requestLeave('2026-05-04');

        $this->assertNotNull($rule->fresh()->last_fired_at);
        $this->assertDatabaseHas('activity_logs', ['event' => ActivityLog::RULE_FIRED]);
    }

    public function test_a_punch_rule_is_not_consulted_on_a_leave_request(): void
    {
        // The two vocabularies do not overlap, and a punch rule left active
        // must not half-match a request that has no punch status to speak of.
        $rule = $this->rule();  // punch trigger, status is late

        $this->requestLeave('2026-05-04');

        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_a_rule_on_the_leave_type_fires_only_for_that_type(): void
    {
        $sick = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sick Leave',
            'days_per_year' => 10, 'is_active' => true, 'requires_approval' => true,
        ]);

        $rule = $this->rule([
            'trigger'    => PolicyRule::TRIGGER_LEAVE,
            'name'       => 'Sick leave',
            'conditions' => [['field' => 'leave_type_id', 'operator' => 'is', 'value' => $sick->id]],
            'actions'    => [['type' => PolicyRule::ACTION_NOTIFY_ROLE, 'role' => 'hr']],
        ]);

        // Annual leave first: the rule names sick leave, so it stays quiet.
        $this->requestLeave('2026-05-04');
        $this->assertNull($rule->fresh()->last_fired_at);

        app(LeaveService::class)->submit($this->employee->fresh(), [
            'leave_type_id' => $sick->id,
            'start_date'    => '2026-06-01',
            'end_date'      => '2026-06-01',
        ]);

        $this->assertNotNull($rule->fresh()->last_fired_at);
        Notification::assertSentTo($this->hr, PolicyRuleFired::class);
    }

    public function test_short_notice_is_measured_from_today_and_can_be_negative(): void
    {
        // The figure no column holds, and the reason the leave trigger is worth
        // having: leave booked after it has already started. `at_most 0` is the
        // rule somebody actually writes, and it must not catch a fortnight of
        // notice as well.
        Carbon::setTestNow(Carbon::parse('2026-05-10 09:00:00', 'UTC'));

        $rule = $this->rule([
            'trigger'    => PolicyRule::TRIGGER_LEAVE,
            'name'       => 'Backdated leave',
            'conditions' => [['field' => 'notice_days', 'operator' => 'at_most', 'value' => 0]],
            'actions'    => [['type' => PolicyRule::ACTION_NOTIFY_ROLE, 'role' => 'hr']],
        ]);

        // Booked three weeks ahead — plenty of notice, nothing to report.
        $this->requestLeave('2026-06-01');
        $this->assertNull($rule->fresh()->last_fired_at);

        // Booked for last Friday, which is what the rule is for.
        app(LeaveService::class)->submit($this->employee->fresh(), [
            'leave_type_id' => LeaveType::where('name', 'Annual Leave')->value('id'),
            'start_date'    => '2026-05-08',
            'end_date'      => '2026-05-08',
        ]);

        $this->assertNotNull($rule->fresh()->last_fired_at);
        Notification::assertSentTo($this->hr, PolicyRuleFired::class);
    }

    public function test_a_days_rule_compares_the_length_of_the_request(): void
    {
        $rule = $this->rule([
            'trigger'    => PolicyRule::TRIGGER_LEAVE,
            'name'       => 'Long absences',
            'conditions' => [['field' => 'days', 'operator' => 'at_least', 'value' => 5]],
            'actions'    => [['type' => PolicyRule::ACTION_LOG]],
        ]);

        // Two days.
        $this->requestLeave('2026-05-04', '2026-05-05');
        $this->assertNull($rule->fresh()->last_fired_at);

        // Monday to Friday — five working days.
        app(LeaveService::class)->submit($this->employee->fresh(), [
            'leave_type_id' => LeaveType::where('name', 'Annual Leave')->value('id'),
            'start_date'    => '2026-06-01',
            'end_date'      => '2026-06-05',
        ]);

        $this->assertNotNull($rule->fresh()->last_fired_at);
    }

    public function test_a_broken_leave_rule_does_not_cost_the_request(): void
    {
        // The leave half of the promise the punch half already keeps: a rule
        // that throws must lose the notification, not the booking.
        $this->rule([
            'trigger'    => PolicyRule::TRIGGER_LEAVE,
            'name'       => 'Broken',
            'conditions' => [],
            'actions'    => [['type' => PolicyRule::ACTION_NOTIFY_ROLE]],  // no role named
        ]);

        [, $request] = $this->requestLeave('2026-05-04');

        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertSame('pending', $request->status);
    }
}
