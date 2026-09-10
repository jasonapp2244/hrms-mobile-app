<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manager as a first-class role: where they land, what they can reach, and
 * — mostly — what they cannot.
 *
 * The scope is the point of this suite. `role:manager` decides who is in the
 * area and `permission:view-team` decides what the area is for, but neither
 * knows *whose* team; that is ManagerScope, and a screen that forgot to apply it
 * would look exactly like the ones that did. So every read is tested twice:
 * once that a manager sees their own people, once that they cannot see somebody
 * else's by asking for them directly.
 */
class ManagerRoleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;

    protected Employee $manager;
    protected User $managerUser;

    protected Employee $report;

    protected Employee $otherManager;
    protected User $otherManagerUser;
    protected Employee $otherReport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // A Monday, so "today" is a working day under the default week and an
        // absence actually means something.
        $this->travelTo(Carbon::parse('2026-08-03 10:00:00'));

        $this->company = Company::create(['name' => 'Klutch', 'timezone' => 'UTC']);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Depot']);
        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Cleaning',
        ]);

        [$this->manager, $this->managerUser] = $this->staff('Mia', 'M1', 'manager');
        [$this->report] = $this->staff('Raj', 'R1', 'employee', $this->manager);

        // A second manager with their own report. Everything this suite says
        // about "somebody else's team" is said against these two.
        [$this->otherManager, $this->otherManagerUser] = $this->staff('Owen', 'O1', 'manager');
        [$this->otherReport] = $this->staff('Nina', 'N1', 'employee', $this->otherManager);
    }

    /** @return array{0: Employee, 1: User} */
    protected function staff(
        string $name,
        string $code,
        string $role = 'employee',
        ?Employee $reportsTo = null,
    ): array {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($name) . uniqid() . '@test.local',
            'password' => 'password',
            'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'first_name' => $name,
            'last_name' => 'Test',
            'status' => 'active',
            'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'manager_id' => $reportsTo?->id,
        ]);

        return [$employee, $user];
    }

    /** A signed-in user holding an admin-side role, with no employee record. */
    protected function adminUser(string $role = 'admin'): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . uniqid() . '@test.local',
            'password' => 'password',
            'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function punch(Employee $employee, string $type = 'in'): AttendanceLog
    {
        return app(AttendanceService::class)->record($employee, $this->office)['log'];
    }

    // ---------------------------------------------------------------- routing

    public function test_a_manager_signing_in_lands_on_their_own_dashboard(): void
    {
        $user = User::create([
            'name' => 'Landing', 'email' => 'landing@test.local',
            'password' => 'a-strong-password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole('manager');

        $this->post(route('login'), [
            'email' => 'landing@test.local',
            'password' => 'a-strong-password',
        ])->assertRedirect(route('manager.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_home_route_is_per_role(): void
    {
        $this->assertSame('manager.dashboard', $this->managerUser->homeRoute());
        $this->assertSame('dashboard', $this->adminUser('admin')->homeRoute());
        $this->assertSame('dashboard', $this->adminUser('hr')->homeRoute());
        $this->assertSame('employee.dashboard', $this->report->user->homeRoute());
    }

    public function test_an_admin_who_also_manages_a_team_still_lands_on_the_staff_dashboard(): void
    {
        // The larger screen wins, and their manager area stays one click away.
        // The reverse would take an administrator to a page showing four
        // cleaners with no way back.
        $user = $this->adminUser('admin');
        $user->assignRole('manager');

        $this->assertSame('dashboard', $user->fresh()->homeRoute());
    }

    public function test_the_root_redirect_follows_the_manager_home(): void
    {
        $this->actingAs($this->managerUser)
            ->get('/')
            ->assertRedirect(route('manager.dashboard'));
    }

    public function test_a_manager_can_still_reach_the_portal_where_they_clock_in(): void
    {
        // A manager is a member of staff. Gaining a dashboard must not cost them
        // the ability to start their own shift.
        $this->actingAs($this->managerUser)
            ->get(route('employee.dashboard'))
            ->assertOk();
    }

    public function test_logging_out_still_works(): void
    {
        $this->actingAs($this->managerUser)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------- authorisation

    public static function managerRoutes(): array
    {
        return [
            'dashboard'  => ['manager.dashboard', []],
            'team'       => ['manager.team.index', []],
            'attendance' => ['manager.attendance.index', []],
            'punch log'  => ['manager.attendance.logs', []],
            'schedule'   => ['manager.schedule.index', []],
            'approvals'  => ['manager.approvals.index', []],
            'reports'    => ['manager.reports.show', ['type' => 'late']],
        ];
    }

    /** @dataProvider managerRoutes */
    public function test_a_manager_can_reach_every_screen_in_their_area(string $route, array $params): void
    {
        $this->actingAs($this->managerUser)
            ->get(route($route, $params))
            ->assertOk();
    }

    /** @dataProvider managerRoutes */
    public function test_an_ordinary_employee_is_refused_the_manager_area(string $route, array $params): void
    {
        $this->actingAs($this->report->user)
            ->get(route($route, $params))
            ->assertForbidden();
    }

    /** @dataProvider managerRoutes */
    public function test_the_manager_area_needs_a_sign_in(string $route, array $params): void
    {
        $this->get(route($route, $params))->assertRedirect(route('login'));
    }

    /** @dataProvider managerRoutes */
    public function test_an_hr_user_without_the_manager_role_is_refused(string $route, array $params): void
    {
        // Not a slight on HR — they have their own, larger screens. The point is
        // that the area is gated on the role rather than on seniority, so it
        // cannot be reached by holding a permission that happens to overlap.
        $this->actingAs($this->adminUser('hr'))
            ->get(route($route, $params))
            ->assertForbidden();
    }

    public function test_withdrawing_view_team_closes_the_area_without_touching_the_routes(): void
    {
        // The gate the roles editor can actually turn. `view-team` was seeded to
        // the manager role and wired to nothing before this area existed.
        $role = \Spatie\Permission\Models\Role::findByName('manager');
        $role->revokePermissionTo('view-team');
        app()['cache']->forget('spatie.permission.cache');

        $this->actingAs($this->managerUser->fresh())
            ->get(route('manager.dashboard'))
            ->assertForbidden();
    }

    public static function adminRoutes(): array
    {
        return [
            'staff dashboard' => ['dashboard', []],
            'employees'       => ['employees.index', []],
            'settings'        => ['settings.index', []],
            'roles'           => ['roles.index', []],
            'activity log'    => ['activity.index', []],
            'policies'        => ['policies.edit', []],
            'company'         => ['company.index', []],
            'offices'         => ['offices.index', []],
            'leave register'  => ['leave.index', []],
            'hr late report'  => ['reports.late', []],
            'roster planner'  => ['shifts.roster', []],
            'org chart'       => ['employees.org-chart', []],
            // B5.5. A team lead speaking to their own reports is a reasonable
            // feature; this one broadcasts to a whole department or office, so
            // the role does not hold it.
            'announcements'   => ['announcements.index', []],
        ];
    }

    /** @dataProvider adminRoutes */
    public function test_a_manager_is_refused_every_admin_screen(string $route, array $params): void
    {
        $this->actingAs($this->managerUser)
            ->get(route($route, $params))
            ->assertForbidden();
    }

    public function test_a_manager_cannot_mint_or_promote_a_login(): void
    {
        // The escalation that matters: if a manager could create an account or
        // change its role, they could make themselves an admin. Both endpoints
        // sit behind manage-employees inside the admin group, which the role
        // cannot pass at all.
        $this->actingAs($this->managerUser)
            ->post(route('employees.account.store', $this->report), [
                'email' => 'new@test.local',
                'role' => 'admin',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ])
            ->assertForbidden();

        $this->actingAs($this->managerUser)
            ->post(route('employees.account.role', $this->report), ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_a_manager_cannot_write_to_the_attendance_record(): void
    {
        // Attendance is append-only and every write records an actor. Keying a
        // punch in or striking one out is manage-attendance, which this role
        // does not hold — a manager's route back is the employee's
        // regularisation request or HR's correction screen.
        $log = $this->punch($this->report);

        $this->actingAs($this->managerUser)
            ->post(route('attendance.manual'), [
                'employee_id' => $this->report->id,
                'work_date' => '2026-08-03',
            ])
            ->assertForbidden();

        $this->actingAs($this->managerUser)
            ->post(route('attendance.void', $log), ['reason' => 'nope'])
            ->assertForbidden();
    }

    public function test_a_manager_login_with_no_employee_record_is_refused_not_broken(): void
    {
        // The role grants the area; the reporting line lives on the employee
        // row. Without one there is no scope, and every screen here would be
        // about nobody.
        $orphan = User::create([
            'name' => 'Orphan', 'email' => 'orphan@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $orphan->assignRole('manager');

        $this->actingAs($orphan)
            ->get(route('manager.dashboard'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ scope

    public function test_the_team_list_shows_only_direct_reports(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->get(route('manager.team.index'))
            ->assertOk()
            ->assertSee('Raj')
            ->assertDontSee('Nina');

        // Their own record is not on their own team — they do not report to
        // themselves, and Employee::canReportTo makes that impossible.
        //
        // Asserted against the view data rather than as assertDontSee('M1').
        // That was a two-character search of a whole rendered page, and the
        // CSRF token is forty random alphanumerics: roughly one run in a
        // hundred contained "M1" by chance and failed here for no reason. A
        // flake in a suite this size is worse than a missing assertion, because
        // it teaches everyone to re-run rather than read.
        $listed = collect($response->viewData('rows'))
            ->map(fn (array $row) => $row['employee']->id)
            ->all();

        $this->assertNotContains(
            $this->manager->id,
            $listed,
            'A manager must not appear on their own team list.',
        );
    }

    public function test_a_manager_cannot_open_somebody_elses_report(): void
    {
        // The route-model binding resolves any employee in the table, so the
        // scope check is the only thing between this and every record in the
        // company. Straight IDOR.
        $this->actingAs($this->managerUser)
            ->get(route('manager.team.show', $this->otherReport))
            ->assertForbidden();
    }

    public function test_a_manager_cannot_open_another_manager(): void
    {
        $this->actingAs($this->managerUser)
            ->get(route('manager.team.show', $this->otherManager))
            ->assertForbidden();
    }

    public function test_a_manager_cannot_open_their_own_record_through_the_team_screen(): void
    {
        // Not a slight — their own details are on their profile. The point is
        // that the scope is "reports to me", and a manager is not their own
        // report, so the answer has to be the same refusal as for anyone else.
        $this->actingAs($this->managerUser)
            ->get(route('manager.team.show', $this->manager))
            ->assertForbidden();
    }

    public function test_a_manager_can_open_their_own_report(): void
    {
        $this->actingAs($this->managerUser)
            ->get(route('manager.team.show', $this->report))
            ->assertOk()
            ->assertSee('Raj');
    }

    public function test_a_terminated_report_is_still_readable(): void
    {
        // Deleting an employee with history is refused; terminating is the way
        // out. Their records are the reason for that refusal, so a manager must
        // still be able to read them.
        $this->report->update(['status' => 'terminated']);

        $this->actingAs($this->managerUser)
            ->get(route('manager.team.show', $this->report))
            ->assertOk();
    }

    public function test_the_punch_log_never_reaches_past_the_team(): void
    {
        $this->punch($this->report);
        $this->punch($this->otherReport);

        $this->actingAs($this->managerUser)
            ->get(route('manager.attendance.logs'))
            ->assertOk()
            ->assertSee('Raj')
            ->assertDontSee('Nina');
    }

    public function test_filtering_the_punch_log_by_an_outsider_narrows_rather_than_widens(): void
    {
        // The id travels in the query string. Handed one outside the team the
        // filter must return nothing — never fall back to "everyone", and never
        // honour it.
        $this->punch($this->report);
        $this->punch($this->otherReport);

        // Asserted on the result set rather than on the page text: the filter
        // dropdown legitimately lists the manager's own team, so "Raj" appears
        // in the markup whether or not any of his punches were returned.
        $logs = $this->actingAs($this->managerUser)
            ->get(route('manager.attendance.logs', ['employee_id' => $this->otherReport->id]))
            ->assertOk()
            ->assertDontSee('Nina')
            ->viewData('logs');

        $this->assertCount(0, $logs, 'An id outside the team must match nothing at all.');
    }

    public function test_the_schedule_shows_only_the_teams_published_days(): void
    {
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Earlies',
            'start_time' => '06:00', 'end_time' => '14:00',
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->report->id, 'shift_id' => $shift->id,
            'date' => '2026-08-04', 'published_at' => now(),
        ]);
        ShiftAssignment::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->otherReport->id, 'shift_id' => $shift->id,
            'date' => '2026-08-04', 'published_at' => now(),
        ]);

        $this->actingAs($this->managerUser)
            ->get(route('manager.schedule.index'))
            ->assertOk()
            ->assertSee('Raj')
            ->assertDontSee('Nina');
    }

    public function test_a_draft_shift_is_invisible_to_the_manager_too(): void
    {
        // Published only, exactly as the employee sees it. A manager reading a
        // draft would tell somebody to come in on a day still being moved.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Nightshift',
            'start_time' => '22:00', 'end_time' => '06:00',
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->report->id, 'shift_id' => $shift->id,
            'date' => '2026-08-04', 'published_at' => null,
        ]);

        $this->actingAs($this->managerUser)
            ->get(route('manager.schedule.index'))
            ->assertOk()
            ->assertDontSee('Nightshift');
    }

    // -------------------------------------------------------------- dashboard

    public function test_the_dashboard_counts_the_team_from_real_rows(): void
    {
        [$second] = $this->staff('Sam', 'S1', 'employee', $this->manager);

        $this->punch($this->report);

        $response = $this->actingAs($this->managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk();

        $summary = $response->viewData('summary');

        $this->assertSame(2, $summary['total'], 'Both reports should be counted.');
        $this->assertSame(1, $summary['present'], 'Only one of them clocked in.');
        $this->assertSame(1, $summary['in_now'], 'And they have not clocked out.');
        $this->assertSame(1, $summary['absent'], 'The other is unaccounted for on a working day.');

        // The outsider is nowhere in it.
        $this->assertFalse(
            $response->viewData('team')->contains('id', $this->otherReport->id),
            'Another manager’s report must never appear in this team.',
        );
    }

    public function test_a_manager_with_nobody_reporting_gets_an_honest_empty_screen(): void
    {
        [$lonely, $lonelyUser] = $this->staff('Lee', 'L1', 'manager');

        $this->actingAs($lonelyUser)
            ->get(route('manager.dashboard'))
            ->assertOk()
            // A real configuration, not an error: the role grants the gate and
            // manager_id decides the scope, and the two are set separately.
            ->assertSee('Nobody reports to you yet');
    }

    public function test_leave_waiting_on_this_manager_appears_and_somebody_elses_does_not(): void
    {
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual',
            'days_per_year' => 20, 'is_active' => true,
        ]);

        $mine = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-10',
            'end_date' => '2026-08-10', 'days' => 1, 'status' => 'pending',
            'reason' => 'Dentist',
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->otherReport->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-10',
            'end_date' => '2026-08-10', 'days' => 1, 'status' => 'pending',
            'reason' => 'Wedding',
        ]);

        $pending = $this->actingAs($this->managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk()
            ->viewData('pendingLeave');

        $this->assertTrue($pending->contains('id', $mine->id));
        $this->assertCount(1, $pending, 'Only this manager’s own team should be waiting on them.');
    }

    public function test_a_shift_open_from_a_previous_day_is_flagged(): void
    {
        // Yesterday's punch, never closed. Today's open punch is deliberately
        // not flagged — somebody mid-shift is not a problem.
        $this->travelTo(Carbon::parse('2026-08-04 10:00:00'));

        AttendanceLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->report->id,
            'office_id' => $this->office->id,
            'type' => 'in',
            'status' => 'ontime',
            'work_date' => '2026-08-03',
            'scanned_at' => Carbon::parse('2026-08-03 09:00:00'),
            'source' => 'button',
        ]);

        $missing = $this->actingAs($this->managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk()
            ->viewData('missingCheckouts');

        $this->assertCount(1, $missing);
        $this->assertSame($this->report->id, $missing->first()->employee_id);
    }

    public function test_todays_open_punch_is_not_reported_as_missing(): void
    {
        $this->punch($this->report);

        $missing = $this->actingAs($this->managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk()
            ->viewData('missingCheckouts');

        $this->assertCount(0, $missing, 'Somebody three hours into a shift is not a problem.');
    }

    // ---------------------------------------------------------------- reports

    public function test_a_team_report_counts_only_the_team(): void
    {
        // Both are late; only one is this manager's.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Nine to five',
            'start_time' => '09:00', 'end_time' => '17:00',
        ]);
        $this->department->update(['shift_id' => $shift->id]);

        $this->travelTo(Carbon::parse('2026-08-03 11:30:00'));
        $this->punch($this->report);
        $this->punch($this->otherReport);

        $response = $this->actingAs($this->managerUser)
            ->get(route('manager.reports.show', ['type' => 'late', 'from' => '2026-08-01', 'to' => '2026-08-03']))
            ->assertOk();

        $rows = $response->viewData('rows');

        $this->assertCount(1, $rows, 'The other manager’s late report must not be in here.');
        $this->assertSame('Raj Test', $rows[0]['Employee']);
    }

    public function test_an_unknown_report_type_is_not_found(): void
    {
        // The type comes off the URL and is used to pick a service method. It is
        // matched against a declared list rather than passed through.
        $this->actingAs($this->managerUser)
            ->get(route('manager.reports.show', ['type' => 'payroll']))
            ->assertNotFound();
    }

    public function test_a_manager_with_no_team_gets_an_empty_report_not_the_companys(): void
    {
        // The scope travels as an id list, and an empty list must mean nobody.
        // Reading it as "no filter" would hand a manager with no reports the
        // whole company — the exact bug the `!== null` check exists to prevent.
        [$lonely, $lonelyUser] = $this->staff('Lee', 'L1', 'manager');

        $this->punch($this->report);
        $this->punch($this->otherReport);

        $response = $this->actingAs($lonelyUser)
            ->get(route('manager.reports.show', ['type' => 'weekly']))
            ->assertOk();

        $this->assertSame(0, $response->viewData('team_size'));
    }

    public function test_a_backwards_date_range_is_swapped_rather_than_returning_nothing(): void
    {
        $response = $this->actingAs($this->managerUser)
            ->get(route('manager.reports.show', ['type' => 'late', 'from' => '2026-08-31', 'to' => '2026-08-01']))
            ->assertOk();

        $this->assertSame('2026-08-01', $response->viewData('from'));
        $this->assertSame('2026-08-31', $response->viewData('to'));
    }

    // -------------------------------------------------------------- approvals

    public function test_the_approvals_inbox_renders_in_the_manager_shell(): void
    {
        $this->actingAs($this->managerUser)
            ->get(route('manager.approvals.index'))
            ->assertOk()
            // The manager sidebar, not the portal's pills — same controller,
            // same scope, different frame.
            ->assertSee(route('manager.team.index'));
    }

    public function test_the_portal_copy_of_the_inbox_still_works(): void
    {
        // The write path lives on the portal routes and is shared by both
        // shells. Breaking it would break approving from either.
        $this->actingAs($this->managerUser)
            ->get(route('employee.approvals.index'))
            ->assertOk();
    }
}
