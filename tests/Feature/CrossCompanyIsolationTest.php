<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AttendanceRegularisation;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One company's administrator must not reach another company's records.
 *
 * `CLAUDE.md` records that "the schema is company-scoped throughout, so
 * [multi-company tenancy] is a routing and onboarding job rather than a
 * data-model one". That claim is the foundation A2.10 would be built on, and it
 * was worth testing rather than trusting: the column existing on 25 tables says
 * nothing about whether every query filters on it.
 *
 * **Route-model binding is where this leaks.** `Route::resource('employees')`
 * hands the controller whatever id is in the URL, with no idea which company
 * asked. Three different guard idioms are in use across the controllers —
 * `authorizeCompany`, `authoriseCompany`, and a bare
 * `abort_unless($x->company_id === ...)` — plus ownership and team checks in
 * the self-service and manager paths. Reading each one proves nothing about the
 * next one somebody adds; attempting the crossing does.
 *
 * Every case here drives a **real route** with the other company's id and
 * expects a refusal. A 200 is a cross-tenant read. A 500 is a leak with a stack
 * trace on it.
 */
class CrossCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> Everything belonging to the company we may not touch. */
    protected array $theirs;

    /** @var array<string, mixed> Ours, for the roles that are not an administrator. */
    protected array $ours;

    protected User $ourAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->ours = $this->makeCompany('Acme');
        $this->theirs = $this->makeCompany('Rival');
        $this->ourAdmin = $this->ours['admin'];
    }

    /** A whole company, with one of everything worth trying to reach. */
    private function makeCompany(string $name): array
    {
        $company = Company::create([
            'name' => $name, 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $office = Office::create(['company_id' => $company->id, 'name' => "{$name} HQ"]);

        $shift = Shift::create([
            'company_id' => $company->id, 'name' => "{$name} Day",
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $company->id, 'name' => "{$name} Ops", 'shift_id' => $shift->id,
        ]);

        $designation = Designation::create([
            'company_id' => $company->id, 'name' => "{$name} Analyst",
        ]);

        $admin = User::create([
            'name' => "{$name} Admin", 'email' => strtolower($name) . '.admin@test.local',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $admin->assignRole('admin');

        $staffUser = User::create([
            'name' => "{$name} Staff", 'email' => strtolower($name) . '.staff@test.local',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $staffUser->assignRole('employee');

        $employee = Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id,
            'designation_id' => $designation->id, 'office_id' => $office->id,
            'user_id' => $staffUser->id, 'employee_code' => strtoupper($name[0]) . '1',
            'first_name' => $name, 'last_name' => 'Staff', 'status' => 'active',
            'shift_id' => $shift->id,
        ]);

        $holiday = Holiday::create([
            'company_id' => $company->id, 'name' => "{$name} Day Off", 'date' => '2026-12-25',
        ]);

        $leaveType = LeaveType::create([
            'company_id' => $company->id, 'name' => "{$name} Annual", 'days_per_year' => 20,
        ]);

        $leaveRequest = LeaveRequest::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-10-01',
            'end_date' => '2026-10-02', 'days' => 2, 'status' => 'pending',
        ]);

        $announcement = Announcement::create([
            'company_id' => $company->id, 'title' => "{$name} notice",
            'body' => 'Internal.', 'audience' => 'all', 'created_by' => $admin->id,
        ]);

        $template = ChecklistTemplate::create([
            'company_id' => $company->id, 'kind' => 'onboarding',
            'title' => "{$name} onboarding step", 'due_offset_days' => 1,
        ]);

        $document = EmployeeDocument::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'type' => 'contract', 'title' => "{$name} contract",
            'original_name' => 'contract.pdf',
            'path' => "employee-documents/{$employee->id}/contract.pdf",
            'uploaded_by_user_id' => $admin->id,
        ]);

        // A manager with a real direct report, so the manager area is reachable:
        // `/manager/*` is gated on the `manager` role *and* `view-team`, and the
        // Team screens are scoped to whoever actually reports to them.
        $managerUser = User::create([
            'name' => "{$name} Manager", 'email' => strtolower($name) . '.manager@test.local',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $managerUser->assignRole('employee');
        $managerUser->assignRole('manager');

        $manager = Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id,
            'office_id' => $office->id, 'user_id' => $managerUser->id,
            'employee_code' => strtoupper($name[0]) . 'M', 'first_name' => $name,
            'last_name' => 'Manager', 'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $employee->update(['manager_id' => $manager->id]);

        $regularisation = AttendanceRegularisation::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'office_id' => $office->id, 'work_date' => '2026-09-01',
            'type' => 'in', 'requested_at' => '2026-09-01 09:00:00',
            'reason' => "{$name} forgot to clock in", 'status' => 'pending',
        ]);

        $swap = ShiftSwapRequest::create([
            'company_id' => $company->id, 'requester_id' => $employee->id,
            'requester_date' => '2026-10-05', 'target_id' => $manager->id,
            'target_date' => '2026-10-06', 'reason' => "{$name} swap",
            'status' => 'pending',
        ]);

        return compact(
            'company', 'office', 'shift', 'department', 'designation', 'admin',
            'staffUser', 'employee', 'holiday', 'leaveType', 'leaveRequest',
            'announcement', 'template', 'document', 'managerUser', 'manager',
            'regularisation', 'swap',
        );
    }

    /**
     * A refusal, of whichever kind. 403 and 404 are both correct answers — some
     * controllers hide the record's existence and some say plainly that it is
     * not yours — but 200 is a read and 500 is a leak with a stack trace on it.
     */
    private function assertRefused(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            "{$what} answered {$response->getStatusCode()} for another company's record",
        );
    }

    // -------------------------------------------------------------------------
    // Reading another company's records
    // -------------------------------------------------------------------------

    public function test_an_admin_cannot_open_another_companys_employee(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->get(route('employees.show', $this->theirs['employee'])),
            'employees.show',
        );

        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->get(route('employees.edit', $this->theirs['employee'])),
            'employees.edit',
        );
    }

    public function test_an_admin_cannot_list_another_companys_employee_documents(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->get(route('employees.documents.index', $this->theirs['employee'])),
            'employees.documents.index',
        );
    }

    public function test_an_admin_cannot_open_another_companys_checklist(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->get(route('checklists.employee', $this->theirs['employee'])),
            'checklists.employee',
        );
    }

    // -------------------------------------------------------------------------
    // Changing another company's records
    // -------------------------------------------------------------------------

    public function test_an_admin_cannot_edit_another_companys_employee(): void
    {
        $response = $this->actingAs($this->ourAdmin)->put(
            route('employees.update', $this->theirs['employee']),
            ['first_name' => 'Taken', 'last_name' => 'Over', 'status' => 'active', 'work_mode' => 'office'],
        );

        $this->assertRefused($response, 'employees.update');
        $this->assertSame('Rival', $this->theirs['employee']->fresh()->first_name);
    }

    public function test_an_admin_cannot_rename_another_companys_department(): void
    {
        $response = $this->actingAs($this->ourAdmin)->put(
            route('departments.update', $this->theirs['department']),
            ['name' => 'Renamed'],
        );

        $this->assertRefused($response, 'departments.update');
        $this->assertSame('Rival Ops', $this->theirs['department']->fresh()->name);
    }

    public function test_an_admin_cannot_rename_another_companys_shift(): void
    {
        $response = $this->actingAs($this->ourAdmin)->put(
            route('shifts.update', $this->theirs['shift']),
            [
                'name' => 'Renamed', 'start_time' => '08:00', 'end_time' => '16:00',
                'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => 1,
            ],
        );

        $this->assertRefused($response, 'shifts.update');
        $this->assertSame('Rival Day', $this->theirs['shift']->fresh()->name);
    }

    public function test_an_admin_cannot_rename_another_companys_office(): void
    {
        $response = $this->actingAs($this->ourAdmin)->put(
            route('offices.update', $this->theirs['office']),
            ['name' => 'Renamed'],
        );

        $this->assertRefused($response, 'offices.update');
        $this->assertSame('Rival HQ', $this->theirs['office']->fresh()->name);
    }

    public function test_an_admin_cannot_rename_another_companys_leave_type(): void
    {
        $response = $this->actingAs($this->ourAdmin)->put(
            route('leave-types.update', $this->theirs['leaveType']),
            ['name' => 'Renamed', 'days_per_year' => 5],
        );

        $this->assertRefused($response, 'leave-types.update');
        $this->assertSame('Rival Annual', $this->theirs['leaveType']->fresh()->name);
    }

    // -------------------------------------------------------------------------
    // Destroying another company's records — the worst case
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> route name, fixture key */
    public static function destroyableRecords(): array
    {
        return [
            'employee'     => ['employees.destroy', 'employee'],
            'department'   => ['departments.destroy', 'department'],
            'designation'  => ['designations.destroy', 'designation'],
            'office'       => ['offices.destroy', 'office'],
            'shift'        => ['shifts.destroy', 'shift'],
            'holiday'      => ['holidays.destroy', 'holiday'],
            'leave type'   => ['leave-types.destroy', 'leaveType'],
            'announcement' => ['announcements.destroy', 'announcement'],
        ];
    }

    /**
     * @dataProvider destroyableRecords
     */
    public function test_an_admin_cannot_delete_another_companys_record(string $route, string $key): void
    {
        $record = $this->theirs[$key];

        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->delete(route($route, $record)),
            $route,
        );

        // Still there. A refusal that deleted the row anyway would be worse
        // than no check at all, because the status code would say it was safe.
        $this->assertNotNull($record->fresh(), "{$route} deleted another company's record");
    }

    public function test_an_admin_cannot_publish_another_companys_announcement(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->post(route('announcements.publish', $this->theirs['announcement'])),
            'announcements.publish',
        );

        $this->assertNull($this->theirs['announcement']->fresh()->published_at);
    }

    public function test_an_admin_cannot_decide_another_companys_leave(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ourAdmin)->post(route('leave.approve', $this->theirs['leaveRequest'])),
            'leave.approve',
        );

        // Still pending. Approving another company's leave would spend a
        // balance in a company this administrator has no standing in.
        $this->assertSame('pending', $this->theirs['leaveRequest']->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // The API, which is reachable from any handset on the internet
    // -------------------------------------------------------------------------

    public function test_the_api_refuses_another_companys_leave_request(): void
    {
        Sanctum::actingAs($this->ourAdmin);

        $this->assertRefused(
            $this->getJson('/api/v1/leave/requests/' . $this->theirs['leaveRequest']->id),
            'GET /leave/requests/{id}',
        );
    }

    public function test_the_api_refuses_to_cancel_another_companys_leave(): void
    {
        Sanctum::actingAs($this->ourAdmin);

        $this->assertRefused(
            $this->postJson('/api/v1/leave/requests/' . $this->theirs['leaveRequest']->id . '/cancel'),
            'POST /leave/requests/{id}/cancel',
        );

        $this->assertSame('pending', $this->theirs['leaveRequest']->fresh()->status);
    }

    public function test_the_api_refuses_another_companys_document(): void
    {
        Sanctum::actingAs($this->ourAdmin);

        // The vault holds passport scans and contracts. This is the single
        // worst thing on the list to get wrong.
        $this->assertRefused(
            $this->getJson('/api/v1/documents/' . $this->theirs['document']->id),
            'GET /documents/{id}',
        );
    }

    // -------------------------------------------------------------------------
    // Listings, which leak by including rather than by answering
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // A user with no company of their own
    // -------------------------------------------------------------------------

    /**
     * Every controller used to resolve the current company as
     * `auth()->user()->company_id ?? Office::value('company_id')`, copied into
     * twenty-three of them. On a single-company install that fallback was a
     * harmless convenience; with a second company on the box it hands a user
     * who has no company **somebody else's data**, silently and with a 200.
     *
     * Nothing reached it — `users.company_id` is nullable but both paths that
     * create a user set it — which is exactly why it was worth closing while
     * that was still true rather than after somebody added a third path.
     */
    private function companylessAdmin(): User
    {
        $user = User::create([
            'name' => 'Orphan Admin', 'email' => 'orphan@test.local',
            'password' => Hash::make('password'), 'company_id' => null,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_a_user_with_no_company_is_refused_rather_than_given_one(): void
    {
        $response = $this->actingAs($this->companylessAdmin())->get(route('dashboard'));

        // The old fallback answered 200 here, with company #1's dashboard.
        $this->assertRefused($response, 'dashboard');
    }

    public function test_a_user_with_no_company_sees_nobody_elses_employees(): void
    {
        $response = $this->actingAs($this->companylessAdmin())->get(route('employees.index'));

        $this->assertRefused($response, 'employees.index');
        $response->assertDontSee('Rival');
        $response->assertDontSee('Acme');
    }

    public function test_the_refusal_says_what_is_wrong(): void
    {
        // A blank 403 sends somebody hunting through permissions. The cause is
        // an account that was never linked to a company, and only an
        // administrator can fix it.
        $this->actingAs($this->companylessAdmin())
            ->get(route('shifts.index'))
            ->assertForbidden()
            ->assertSee('not linked to a company', false);
    }

    // -------------------------------------------------------------------------
    // Managers — a narrower role, and a narrower blast radius, but the same rule
    // -------------------------------------------------------------------------

    /**
     * The manager area is doubly gated — `role:manager` at the door and
     * `view-team` inside — and every query behind it is scoped to direct
     * reports. A manager reaching another company therefore has to get past
     * two things rather than one, which is exactly the sort of reasoning that
     * makes people not test it.
     */
    public function test_a_manager_cannot_open_another_companys_employee(): void
    {
        $this->assertRefused(
            $this->actingAs($this->ours['managerUser'])
                ->get(route('manager.team.show', $this->theirs['employee'])),
            'manager.team.show',
        );
    }

    public function test_a_manager_cannot_decide_another_companys_swap(): void
    {
        $swap = $this->theirs['swap'];

        $this->assertRefused(
            $this->actingAs($this->ours['managerUser'])
                ->post(route('employee.swaps.approve', $swap), ['decision_note' => 'Fine by me']),
            'employee.swaps.approve',
        );

        // Approving another company's swap would rewrite two people's roster in
        // a company this manager has no standing in.
        $this->assertSame('pending', $swap->fresh()->status);
    }

    public function test_a_manager_cannot_decide_another_companys_leave(): void
    {
        $leave = $this->theirs['leaveRequest'];

        $this->assertRefused(
            $this->actingAs($this->ours['managerUser'])
                ->post(route('employee.approvals.approve', $leave)),
            'employee.approvals.approve',
        );

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_a_managers_own_screens_never_list_another_company(): void
    {
        foreach (['manager.dashboard', 'manager.team.index', 'manager.attendance.index'] as $route) {
            $this->actingAs($this->ours['managerUser'])
                ->get(route($route))
                ->assertOk()
                ->assertDontSee('Rival');
        }
    }

    // -------------------------------------------------------------------------
    // Self-service — where the guard is ownership, not company
    // -------------------------------------------------------------------------

    /**
     * These routes are reachable by every employee, so they check the record
     * against *this* employee rather than against the company. That is strictly
     * stronger — owning a record implies sharing its company — but "stronger in
     * principle" is not evidence, and the three controllers involved carry no
     * company check at all, so this is the only thing standing between them.
     */
    public function test_an_employee_cannot_withdraw_another_companys_leave(): void
    {
        $leave = $this->theirs['leaveRequest'];

        $this->assertRefused(
            $this->actingAs($this->ours['staffUser'])
                ->post(route('employee.leave.cancel', $leave)),
            'employee.leave.cancel',
        );

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_an_employee_cannot_withdraw_another_companys_correction(): void
    {
        $regularisation = $this->theirs['regularisation'];

        $this->assertRefused(
            $this->actingAs($this->ours['staffUser'])
                ->post(route('employee.regularisations.cancel', $regularisation)),
            'employee.regularisations.cancel',
        );

        $this->assertSame('pending', $regularisation->fresh()->status);
    }

    public function test_an_employee_cannot_withdraw_another_companys_swap(): void
    {
        $swap = $this->theirs['swap'];

        $this->assertRefused(
            $this->actingAs($this->ours['staffUser'])
                ->post(route('employee.swaps.cancel', $swap)),
            'employee.swaps.cancel',
        );

        $this->assertSame('pending', $swap->fresh()->status);
    }

    public function test_an_employees_own_portal_never_shows_another_company(): void
    {
        $this->actingAs($this->ours['staffUser'])
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertDontSee('Rival');
    }

    // -------------------------------------------------------------------------
    // The manager API, which is the same scope reached from a handset
    // -------------------------------------------------------------------------

    public function test_the_team_api_answers_only_for_its_own_company(): void
    {
        Sanctum::actingAs($this->ours['managerUser']);

        foreach (['/api/v1/team/attendance', '/api/v1/team/roster', '/api/v1/team/leave-calendar'] as $endpoint) {
            $body = $this->getJson($endpoint)->assertOk()->getContent();

            // These answer for "my team" with no id in the URL, so the crossing
            // to test is not a refusal but an absence: nobody else's name may
            // appear in the payload.
            $this->assertStringNotContainsString('Rival', $body, "{$endpoint} named another company's staff");
        }
    }

    public function test_the_api_refuses_to_cancel_another_companys_correction(): void
    {
        Sanctum::actingAs($this->ours['staffUser']);

        $this->assertRefused(
            $this->postJson('/api/v1/attendance/regularisations/' . $this->theirs['regularisation']->id . '/cancel'),
            'POST /attendance/regularisations/{id}/cancel',
        );

        $this->assertSame('pending', $this->theirs['regularisation']->fresh()->status);
    }

    public function test_the_directory_api_never_reaches_another_company(): void
    {
        Sanctum::actingAs($this->ours['staffUser']);

        // "Who else works here" is the one endpoint that answers about other
        // people by design, which makes it the one where the company boundary
        // is doing all the work.
        $body = $this->getJson('/api/v1/directory')->assertOk()->getContent();

        $this->assertStringNotContainsString('Rival', $body);
    }

    public function test_listings_never_include_another_companys_rows(): void
    {
        $pages = [
            'employees.index'    => 'Rival',
            'departments.index'  => 'Rival Ops',
            'designations.index' => 'Rival Analyst',
            'offices.index'      => 'Rival HQ',
            'shifts.index'       => 'Rival Day',
            'holidays.index'     => 'Rival Day Off',
            'leave-types.index'  => 'Rival Annual',
        ];

        foreach ($pages as $route => $theirName) {
            $response = $this->actingAs($this->ourAdmin)->get(route($route))->assertOk();

            // A listing does not refuse — it simply must not contain them.
            $response->assertDontSee($theirName);
        }
    }
}
