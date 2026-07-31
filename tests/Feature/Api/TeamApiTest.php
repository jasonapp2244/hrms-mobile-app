<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A manager's view of their own team, and the boundary around it.
 */
class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $manager;
    protected User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-08-03 10:00:00')); // a Monday

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        [$this->manager, $this->managerUser] = $this->staff('Mia', 'M1', 'manager');
    }

    /** @return array{0: Employee, 1: User} */
    protected function staff(
        string $name,
        string $code,
        string $role = 'employee',
        ?Employee $reportsTo = null,
    ): array {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        $employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $name, 'status' => 'active',
            'office_id' => $this->office->id,
            'manager_id' => $reportsTo?->id,
        ]);

        return [$employee, $user];
    }

    protected function punch(Employee $employee): void
    {
        app(AttendanceService::class)->record($employee, $this->office);
    }

    protected function asManager(): void
    {
        Sanctum::actingAs($this->managerUser);
    }

    // ================= the boundary =================

    public function test_an_ordinary_employee_cannot_see_a_team(): void
    {
        [, $user] = $this->staff('Ann', 'E1');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/team/attendance')->assertForbidden();
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson('/api/v1/team/attendance')->assertUnauthorized();
    }

    public function test_a_manager_sees_only_their_own_reports(): void
    {
        // The whole point of the scoping: a team lead is not HR.
        $this->staff('Ann', 'E1', 'employee', $this->manager);
        $this->staff('Bob', 'E2'); // reports to nobody
        [$otherManager] = $this->staff('Otto', 'M2', 'manager');
        $this->staff('Cara', 'E3', 'employee', $otherManager);

        $this->asManager();
        $response = $this->getJson('/api/v1/team/attendance')->assertOk();

        $names = collect($response->json('team'))->pluck('name');
        $this->assertContains('Ann', $names);
        $this->assertNotContains('Bob', $names);
        $this->assertNotContains('Cara', $names);
    }

    public function test_a_manager_with_nobody_reporting_gets_an_empty_team(): void
    {
        // Not an error — the app says "nobody reports to you".
        $this->asManager();

        $this->getJson('/api/v1/team/attendance')
            ->assertOk()
            ->assertJsonPath('team', [])
            ->assertJsonPath('summary.total', 0);
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->asManager();

        $this->getJson('/api/v1/team/attendance?date=2026-12-25')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_range');
    }

    // ================= what it reports =================

    public function test_somebody_who_clocked_in_shows_as_present_and_on_the_clock(): void
    {
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $this->punch($ann);

        $this->asManager();
        $row = $this->getJson('/api/v1/team/attendance')->assertOk()->json('team.0');

        $this->assertSame('present', $row['status']);
        $this->assertTrue($row['is_clocked_in']);
        $this->assertNotNull($row['first_in']);
        $this->assertNull($row['last_out']);
    }

    public function test_somebody_who_worked_and_left_is_present_but_not_in_now(): void
    {
        // The distinction the summary exists for: present for the day is not
        // the same as standing in the building.
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $this->punch($ann);   // in
        $this->travel(2)->hours();
        $this->punch($ann);   // out

        $this->asManager();
        $response = $this->getJson('/api/v1/team/attendance')->assertOk();

        $this->assertSame('present', $response->json('team.0.status'));
        $this->assertFalse($response->json('team.0.is_clocked_in'));
        $this->assertSame(1, $response->json('summary.present'));
        $this->assertSame(0, $response->json('summary.in_now'));
    }

    public function test_somebody_who_did_not_turn_up_on_a_working_day_is_absent(): void
    {
        $this->staff('Ann', 'E1', 'employee', $this->manager);

        $this->asManager();
        $response = $this->getJson('/api/v1/team/attendance')->assertOk();

        $this->assertSame('absent', $response->json('team.0.status'));
        $this->assertSame(1, $response->json('summary.absent'));
    }

    public function test_approved_leave_reads_as_leave_rather_than_absence(): void
    {
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'days_per_year' => 20,
        ]);
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-03', 'days' => 1, 'status' => 'approved',
        ]);

        $this->asManager();
        $response = $this->getJson('/api/v1/team/attendance')->assertOk();

        $this->assertSame('leave', $response->json('team.0.status'));
        $this->assertSame(1, $response->json('summary.on_leave'));
    }

    public function test_turning_up_on_booked_leave_still_counts_as_present(): void
    {
        // Present wins over every reason not to be there — the same rule the
        // employee's own history applies, because it is the same method.
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'days_per_year' => 20,
        ]);
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-03', 'days' => 1, 'status' => 'approved',
        ]);
        $this->punch($ann);

        $this->asManager();

        $this->assertSame(
            'present',
            $this->getJson('/api/v1/team/attendance')->json('team.0.status'),
        );
    }

    public function test_lateness_is_reported_against_the_rostered_shift(): void
    {
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Morning', 'code' => 'MOR',
            'start_time' => '09:00', 'end_time' => '17:00',
            'break_minutes' => 30, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $ann->update(['shift_id' => $shift->id]);

        // Clocked in at 10:00 against a 09:00 shift.
        $this->punch($ann);

        $this->asManager();
        $response = $this->getJson('/api/v1/team/attendance')->assertOk();

        $this->assertTrue($response->json('team.0.late'));
        $this->assertSame(1, $response->json('summary.late'));
        $this->assertSame('Morning', $response->json('team.0.shift.name'));
    }

    public function test_an_inactive_report_is_not_listed(): void
    {
        // Somebody who has left still has a manager_id; they are not the
        // manager's problem any more.
        [$gone] = $this->staff('Gone', 'E9', 'employee', $this->manager);
        $gone->update(['status' => 'inactive']);

        $this->asManager();

        $this->getJson('/api/v1/team/attendance')
            ->assertOk()
            ->assertJsonPath('summary.total', 0);
    }

    public function test_yesterday_can_be_asked_for(): void
    {
        [$ann] = $this->staff('Ann', 'E1', 'employee', $this->manager);
        $this->punch($ann);

        $this->travel(1)->days();
        $this->asManager();

        $response = $this->getJson('/api/v1/team/attendance?date=2026-08-03')->assertOk();

        $this->assertSame('2026-08-03', $response->json('date'));
        $this->assertSame('present', $response->json('team.0.status'));
    }
}
