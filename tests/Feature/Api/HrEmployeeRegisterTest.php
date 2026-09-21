<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The employee register on the phone (client requirement, 2026-09-22).
 *
 * **The risk here is disclosure, not correctness.** `/directory` already
 * answers "who else works here" for every employee in the company, and it is
 * deliberately the most conservative endpoint in the API — no date of birth, no
 * address, no national id, no emergency contact, no reporting line. This
 * endpoint returns all of those, so what has to be proven is that only
 * `manage-employees` reaches it and only for one company.
 *
 * A **manager** is the case worth the most here. They hold `view-team` and can
 * see their own reports' attendance; CLAUDE.md records that they deliberately
 * never see the HR-grade fields, on the web or anywhere. A new endpoint is
 * exactly how that quietly stops being true.
 */
class HrEmployeeRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected User $hr;
    protected Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->hr = $this->account('hr@acme.test', 'hr');

        $this->staff = Employee::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'office_id'     => $this->office->id,
            'employee_code' => 'EMP-0001',
            'first_name'    => 'Ann',
            'last_name'     => 'Lee',
            'status'        => 'active',
            'email'         => 'ann@acme.test',
            'phone'         => '+44 7700 900001',
            'national_id'   => 'NI-99-88-77',
            'address'       => '14 Orchard Lane',
            'date_of_birth' => '1990-04-02',
            'blood_group'   => 'O+',
            'emergency_contact_name'  => 'Joan Lee',
            'emergency_contact_phone' => '+44 7700 900002',
        ]);
    }

    protected function account(string $email, string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // -------------------------------------------------------------------------
    // Who may read the register
    // -------------------------------------------------------------------------

    public function test_hr_sees_the_register(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/employees')
            ->assertOk()
            ->assertJsonPath('people.0.name', 'Ann Lee')
            ->assertJsonPath('people.0.employee_code', 'EMP-0001')
            ->assertJsonPath('people.0.department', 'Ops');
    }

    public function test_a_manager_is_refused_the_register(): void
    {
        // The disclosure test. A manager supervises a team and deliberately
        // never sees the HR-grade fields for them — a new endpoint is exactly
        // how that stops being true without anybody deciding it should.
        $manager = $this->account('lead@acme.test', 'manager');
        $lead = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $manager->id,
            'employee_code' => 'EMP-0002', 'first_name' => 'Mia', 'last_name' => 'Manager',
            'status' => 'active',
        ]);
        $this->staff->update(['manager_id' => $lead->id]);

        $this->assertFalse($manager->can('manage-employees'));

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/hr/employees')->assertForbidden();
        // Not even for their own direct report.
        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")->assertForbidden();
        $this->getJson("/api/v1/hr/employees/{$this->staff->id}/leave")->assertForbidden();
    }

    public function test_an_ordinary_employee_is_refused(): void
    {
        $user = $this->account('ann@acme.test', 'employee');
        $this->staff->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        // Their own record included — the self-service route is /profile, and
        // this one answers about everybody.
        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")->assertForbidden();
    }

    public function test_the_register_needs_a_token(): void
    {
        $this->getJson('/api/v1/hr/employees')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // One company only
    // -------------------------------------------------------------------------

    public function test_another_companys_staff_are_not_listed(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = Employee::create([
            'company_id' => $other->id,
            'employee_code' => 'X-1', 'first_name' => 'Someone', 'last_name' => 'Else',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/employees')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertDontSee('Someone');

        // And not by id either — route-model binding resolves on id alone, and
        // `manage-employees` is company-blind.
        $this->getJson("/api/v1/hr/employees/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/hr/employees/{$theirs->id}/leave")->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // The record itself
    // -------------------------------------------------------------------------

    public function test_the_detail_view_returns_the_hr_grade_record(): void
    {
        // Everything the directory refuses to say, which is the reason this
        // endpoint exists and the reason it is gated the way it is.
        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")
            ->assertOk()
            ->assertJsonPath('employee.national_id', 'NI-99-88-77')
            ->assertJsonPath('employee.address', '14 Orchard Lane')
            ->assertJsonPath('employee.date_of_birth', '1990-04-02')
            ->assertJsonPath('employee.blood_group', 'O+')
            ->assertJsonPath('employee.emergency_contact.name', 'Joan Lee')
            ->assertJsonPath('employee.emergency_contact.phone', '+44 7700 900002');
    }

    public function test_the_directory_still_refuses_what_it_always_refused(): void
    {
        // The other half of the same decision, pinned here because this is the
        // change that could have loosened it. An ordinary employee reading the
        // directory must see no more today than yesterday.
        $user = $this->account('ann@acme.test', 'employee');
        $this->staff->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/directory')->assertOk();

        $this->assertStringNotContainsString('NI-99-88-77', $response->getContent());
        $this->assertStringNotContainsString('Orchard Lane', $response->getContent());
        $this->assertStringNotContainsString('Joan Lee', $response->getContent());
    }

    public function test_the_detail_view_says_whether_the_person_can_sign_in(): void
    {
        // The question HR is asked most often about somebody who says the app
        // will not let them in. The account itself is administered on the web.
        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")
            ->assertOk()
            ->assertJsonPath('employee.has_login', false)
            ->assertJsonPath('employee.login_email', null);

        $user = $this->account('ann@acme.test', 'employee');
        $this->staff->update(['user_id' => $user->id]);

        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")
            ->assertOk()
            ->assertJsonPath('employee.has_login', true)
            ->assertJsonPath('employee.login_email', 'ann@acme.test');
    }

    // -------------------------------------------------------------------------
    // The searching and filtering a register needs
    // -------------------------------------------------------------------------

    public function test_leavers_are_hidden_by_default_and_findable_on_request(): void
    {
        // The difference from the directory, and the reason it is a filter
        // rather than a fixed rule: a directory is for finding somebody who
        // still works here, a register is for answering questions about
        // somebody who did.
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'employee_code' => 'EMP-0009',
            'first_name' => 'Gone', 'last_name' => 'Away', 'status' => 'terminated',
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/employees')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertDontSee('Gone');

        $this->getJson('/api/v1/hr/employees?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'people')
            ->assertSee('Gone');
    }

    public function test_the_register_can_be_searched_by_name_and_code(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'employee_code' => 'EMP-0003',
            'first_name' => 'Sam', 'last_name' => 'Cole', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/employees?q=Cole')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'Sam Cole');

        $this->getJson('/api/v1/hr/employees?q=EMP-0001')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'Ann Lee');
    }

    public function test_a_department_filter_from_another_company_matches_nobody(): void
    {
        // Filtered, not scoped. The company `where` runs first, so a foreign id
        // narrows to nothing rather than reaching past it.
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirDept = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/hr/employees?department_id={$theirDept->id}")
            ->assertOk()
            ->assertJsonCount(0, 'people');
    }

    // -------------------------------------------------------------------------
    // The three things beyond the stored record
    // -------------------------------------------------------------------------

    public function test_every_active_leave_type_appears_even_when_untouched(): void
    {
        // "No balance row" and "nothing taken" look the same on a phone, and
        // only one of them is true. The types come first.
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'is_active' => true,
        ]);
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sick Leave',
            'days_per_year' => 10, 'is_active' => true,
        ]);
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Retired Type',
            'days_per_year' => 5, 'is_active' => false,
        ]);

        Sanctum::actingAs($this->hr);

        $response = $this->getJson("/api/v1/hr/employees/{$this->staff->id}")->assertOk();

        $this->assertCount(2, $response->json('balances'));
        $this->assertSame('Annual Leave', $response->json('balances.0.leave_type'));
        $this->assertSame(20.0, (float) $response->json('balances.0.entitled'));
        $this->assertSame(0.0, (float) $response->json('balances.0.used'));
    }

    public function test_the_attendance_summary_counts_the_last_month(): void
    {
        // Counted, not listed: a phone cannot usefully show thirty rows of
        // punches, and the shape is what HR is actually asking about.
        $today = now()->toDateString();

        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $this->staff->id,
            'office_id' => $this->office->id, 'type' => 'in', 'status' => 'late',
            'scanned_at' => now(), 'work_date' => $today, 'source' => 'mobile',
        ]);
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $this->staff->id,
            'office_id' => $this->office->id, 'type' => 'out', 'status' => 'early_leave',
            'scanned_at' => now(), 'work_date' => $today, 'source' => 'mobile',
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/hr/employees/{$this->staff->id}")
            ->assertOk()
            // One day, not two: both punches belong to the same work_date, and
            // counting rows would report a day worked per punch.
            ->assertJsonPath('attendance.days_worked', 1)
            ->assertJsonPath('attendance.late', 1)
            ->assertJsonPath('attendance.early_leave', 1);
    }

    public function test_one_persons_leave_history_is_its_own_request(): void
    {
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'is_active' => true,
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->staff->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-03-02',
            'end_date' => '2026-03-03', 'days' => 2, 'status' => 'approved',
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/hr/employees/{$this->staff->id}/leave")
            ->assertOk()
            ->assertJsonPath('requests.0.leave_type', 'Annual Leave')
            ->assertJsonPath('requests.0.status', 'approved')
            ->assertJsonPath('requests.0.start_date', '2026-03-02');
    }
}
