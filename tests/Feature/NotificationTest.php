<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestSubmitted;
use App\Services\LeaveService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who hears about a leave request, and when.
 *
 * The routing lives in NotificationService rather than in either controller, so
 * the portal and the mobile API tell the same people about the same event.
 * These tests go through LeaveService for that reason — they exercise the path
 * both surfaces share.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $department;
    protected LeaveType $annual;
    protected Employee $employee;
    protected LeaveService $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->annual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual',
            'days_per_year' => 20, 'requires_approval' => true, 'is_active' => true,
        ]);

        $this->employee = $this->staff('Ann', 'Lee', 'E1', 'employee');

        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        $this->leave = app(LeaveService::class);
    }

    protected function staff(string $first, string $last, string $code, string ...$roles): Employee
    {
        $user = User::create([
            'name' => "{$first} {$last}", 'email' => strtolower($first) . '@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'user_id' => $user->id, 'employee_code' => $code,
            'first_name' => $first, 'last_name' => $last, 'status' => 'active',
        ]);

        return $employee->load('user');
    }

    protected function apply(?Employee $employee = null): LeaveRequest
    {
        return $this->leave->submit($employee ?? $this->employee, [
            'leave_type_id' => $this->annual->id,
            'start_date'    => '2026-08-10',
            'end_date'      => '2026-08-12',
        ]);
    }

    // ================= who hears about a new request =================

    public function test_a_new_request_reaches_the_line_manager(): void
    {
        Notification::fake();

        $manager = $this->staff('Mo', 'Diaz', 'E2', 'employee', 'manager');
        $this->employee->update(['manager_id' => $manager->id]);

        $this->apply();

        Notification::assertSentTo($manager->user, LeaveRequestSubmitted::class);
    }

    public function test_a_request_from_someone_with_no_manager_reaches_hr(): void
    {
        Notification::fake();

        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        // No manager means no queue with an owner — it goes straight to HR, and
        // so does the message about it.
        $this->apply();

        Notification::assertSentTo($hr->user, LeaveRequestSubmitted::class);
    }

    public function test_a_new_request_does_not_go_to_the_whole_company(): void
    {
        Notification::fake();

        $manager = $this->staff('Mo', 'Diaz', 'E2', 'employee', 'manager');
        $this->employee->update(['manager_id' => $manager->id]);

        $bystander = $this->staff('Kim', 'Vale', 'E4', 'employee');
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        $this->apply();

        // While it is with the manager, HR has nothing to do — copying them now
        // trains everyone to ignore the bell.
        Notification::assertNotSentTo($bystander->user, LeaveRequestSubmitted::class);
        Notification::assertNotSentTo($hr->user, LeaveRequestSubmitted::class);
    }

    public function test_a_manager_is_not_copied_on_every_request_in_the_company(): void
    {
        Notification::fake();

        // A manager holds approve-leave, but acts through the manager step for
        // their own team. Treating them as an HR decider would copy them on
        // everybody's leave.
        $otherManager = $this->staff('Mo', 'Diaz', 'E2', 'employee', 'manager');
        $report = $this->staff('Ray', 'Poe', 'E5', 'employee');
        $report->update(['manager_id' => $otherManager->id]);

        $this->apply();   // Ann has no manager, so this is an HR request

        Notification::assertNotSentTo($otherManager->user, LeaveRequestSubmitted::class);
    }

    public function test_a_disabled_account_is_not_notified(): void
    {
        Notification::fake();

        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $hr->user->update(['is_active' => false]);

        $this->apply();

        Notification::assertNotSentTo($hr->user, LeaveRequestSubmitted::class);
    }

    public function test_another_companys_hr_is_not_notified(): void
    {
        Notification::fake();

        $rival = Company::create(['name' => 'Rival', 'timezone' => 'UTC']);
        $outsider = User::create([
            'name' => 'Nosy Parker', 'email' => 'nosy@rival.test',
            'password' => Hash::make('password'), 'company_id' => $rival->id,
        ]);
        $outsider->assignRole('hr');

        $this->apply();

        Notification::assertNotSentTo($outsider, LeaveRequestSubmitted::class);
    }

    public function test_auto_approved_leave_does_not_ask_anybody_to_decide(): void
    {
        Notification::fake();

        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        $casual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Casual',
            'days_per_year' => 5, 'requires_approval' => false, 'is_active' => true,
        ]);

        $this->leave->submit($this->employee, [
            'leave_type_id' => $casual->id,
            'start_date' => '2026-08-10', 'end_date' => '2026-08-10',
        ]);

        // Nobody has to act, so nobody is asked to. The employee is still told.
        Notification::assertNotSentTo($hr->user, LeaveRequestSubmitted::class);
        Notification::assertSentTo($this->employee->user, LeaveRequestDecided::class);
    }

    // ================= the stages =================

    public function test_the_employee_hears_when_the_manager_passes_it_up(): void
    {
        $manager = $this->staff('Mo', 'Diaz', 'E2', 'employee', 'manager');
        $this->employee->update(['manager_id' => $manager->id]);
        $request = $this->apply();

        Notification::fake();
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        $this->leave->managerApprove($request, $manager->user_id);

        // Silence between submitting and a decision is what makes people chase.
        Notification::assertSentTo($this->employee->user, LeaveRequestDecided::class);
        Notification::assertSentTo($hr->user, LeaveRequestSubmitted::class);
    }

    public function test_the_employee_hears_when_leave_is_approved(): void
    {
        $request = $this->apply();

        Notification::fake();
        $this->leave->approve($request);

        Notification::assertSentTo(
            $this->employee->user,
            LeaveRequestDecided::class,
            fn (LeaveRequestDecided $n) => $n->outcome === 'approved',
        );
    }

    public function test_the_employee_hears_when_leave_is_declined_and_why(): void
    {
        $request = $this->apply();

        Notification::fake();
        $this->leave->reject($request, null, 'Too many people out that week.');

        Notification::assertSentTo(
            $this->employee->user,
            LeaveRequestDecided::class,
            fn (LeaveRequestDecided $n) => $n->outcome === 'rejected'
                && $n->leaveRequest->decision_note === 'Too many people out that week.',
        );
    }

    public function test_an_employee_with_no_login_does_not_break_a_decision(): void
    {
        $noLogin = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'employee_code' => 'E9', 'first_name' => 'Paper', 'last_name' => 'Only',
            'status' => 'active',
        ]);

        $request = $this->apply($noLogin);

        // Plenty of staff have a record but no account. Approving their leave
        // must still work — there is simply nobody to send to.
        $this->leave->approve($request);

        $this->assertSame('approved', $request->fresh()->status);
    }

    // ================= what lands =================

    public function test_the_bell_is_written_without_waiting_for_a_queue_worker(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        $this->apply();

        // Queueing the database channel would mean the count does not move
        // until somebody runs queue:work — indistinguishable from broken.
        $this->assertSame(1, $hr->user->unreadNotifications()->count());
    }

    public function test_the_stored_notification_says_what_happened_and_where_to_go(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');

        $this->apply();

        $data = $hr->user->unreadNotifications()->first()->data;

        $this->assertSame('leave.submitted', $data['type']);
        $this->assertStringContainsString('Ann Lee', $data['title']);
        $this->assertStringContainsString('Annual', $data['body']);
        $this->assertStringContainsString('approvals', $data['url']);
    }

    public function test_a_decision_notification_points_at_the_employees_own_leave(): void
    {
        $request = $this->apply();
        $this->leave->approve($request);

        $data = $this->employee->user->unreadNotifications()->first()->data;

        $this->assertSame('leave.approved', $data['type']);
        $this->assertStringContainsString('leave', $data['url']);
    }

    // ================= the screens =================

    public function test_the_centre_lists_your_notifications(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $this->apply();

        $this->actingAs($hr->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ann Lee requested leave');
    }

    public function test_the_centre_shows_only_your_own(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $this->apply();

        $other = $this->staff('Kim', 'Vale', 'E4', 'employee');

        $this->actingAs($other->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Ann Lee requested leave');
    }

    public function test_opening_a_notification_marks_it_read_and_follows_it(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $this->apply();

        $note = $hr->user->unreadNotifications()->first();

        $this->actingAs($hr->user)
            ->get(route('notifications.show', $note->id))
            ->assertRedirect(route('employee.approvals.index'));

        $this->assertSame(0, $hr->user->unreadNotifications()->count());
    }

    public function test_one_person_cannot_open_anothers_notification(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $this->apply();

        $note = $hr->user->unreadNotifications()->first();
        $other = $this->staff('Kim', 'Vale', 'E4', 'employee');

        $this->actingAs($other->user)
            ->get(route('notifications.show', $note->id))
            ->assertNotFound();

        $this->assertSame(1, $hr->user->unreadNotifications()->count());
    }

    public function test_everything_can_be_marked_read_at_once(): void
    {
        $hr = $this->staff('Hana', 'Ruiz', 'E3', 'hr');
        $this->apply();
        $this->apply($this->staff('Ray', 'Poe', 'E5', 'employee'));

        $this->assertSame(2, $hr->user->unreadNotifications()->count());

        $this->actingAs($hr->user)
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $hr->user->fresh()->unreadNotifications()->count());
    }

    public function test_the_centre_needs_a_login(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_an_employee_reaches_the_same_centre_as_staff(): void
    {
        $request = $this->apply();
        $this->leave->approve($request);

        // One inbox, not one per area — a manager is both an employee and an
        // approver and should not have to check two screens.
        $this->actingAs($this->employee->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Your leave was approved');
    }
}
