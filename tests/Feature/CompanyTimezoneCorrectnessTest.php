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
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A New York company, run at the two hours that expose a UTC mistake.
 *
 * Every other suite builds its company in UTC, where "convert to the company
 * zone" and "don't" give the same answer — which is how the manager panel
 * printed every punch four hours early without a test noticing. Here:
 *
 *   - 10:00 in New York (14:00 UTC), for the clock times on screen;
 *   - 21:30 in New York (01:30 UTC the next day), when a UTC "today" has
 *     already turned into tomorrow.
 *
 * Punches are stored as the company's wall clock (AttendanceService writes
 * now($company->tz())), so a stored 09:02 must print as 09:02 AM. Audit
 * stamps — created_at and friends — are real UTC and must be converted.
 */
class CompanyTimezoneCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Employee $manager;
    protected User $managerUser;
    protected Employee $report;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Klutch', 'timezone' => 'America/New_York', 'currency' => 'USD']);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Depot']);
        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Cleaning']);

        [$this->manager, $this->managerUser] = $this->staff('Mia', 'M1', 'manager');
        [$this->report] = $this->staff('Raj', 'R1', 'employee', $this->manager);

        $this->admin = User::create([
            'name' => 'Ada', 'email' => 'ada@test.local', 'password' => 'password',
            'company_id' => $this->company->id,
        ]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $name, string $code, string $role, ?Employee $reportsTo = null): array
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name) . '@test.local', 'password' => 'password',
            'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        $employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $name, 'last_name' => 'Test',
            'status' => 'active', 'office_id' => $this->office->id,
            'department_id' => $this->department->id, 'manager_id' => $reportsTo?->id,
        ]);

        return [$employee, $user];
    }

    /** A punch at a New York wall-clock time, stored the way the service stores one. */
    private function punchAt(string $wallClock, string $type = 'in', string $status = 'ontime'): AttendanceLog
    {
        $at = Carbon::parse($wallClock);

        return AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id,
            'office_id' => $this->office->id, 'type' => $type,
            'scanned_at' => $at, 'work_date' => $at->toDateString(),
            'status' => $status, 'source' => 'button',
        ]);
    }

    // -------------------------------------------------------------------------
    // Clock times on screen
    // -------------------------------------------------------------------------

    public static function managerScreens(): array
    {
        return [
            'dashboard'       => ['manager.dashboard', false],
            'team'            => ['manager.team.index', false],
            'team member'     => ['manager.team.show', true],
            'attendance'      => ['manager.attendance.index', false],
            'punch log'       => ['manager.attendance.logs', false],
        ];
    }

    /** @dataProvider managerScreens */
    public function test_the_manager_panel_prints_the_punch_at_the_time_it_was_made(string $route, bool $withEmployee): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 14:00:00', 'UTC'));   // 10:00 in New York
        $this->punchAt('2026-08-03 09:02:00');

        $this->actingAs($this->managerUser)
            ->get(route($route, $withEmployee ? ['employee' => $this->report->id] : []))
            ->assertOk()
            ->assertSee('09:02 AM')
            ->assertDontSee('05:02 AM');   // stored wall clock, shifted a second time
    }

    public function test_an_audit_stamp_is_shown_in_the_company_zone(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00', 'UTC'));   // 14:00 in New York
        ActivityLog::record(ActivityLog::LOGIN, 'Signed in via web', $this->admin);

        $this->actingAs($this->admin)->get(route('activity.index'))
            ->assertOk()
            ->assertSee('14:00:00')
            ->assertDontSee('18:00:00');
    }

    public function test_the_phone_is_given_a_notification_time_on_the_company_clock(): void
    {
        // 21:30 on the 3rd in New York. Sent in UTC it was "…-04T01:30+00:00",
        // and the handset dated it the 4th.
        $this->travelTo(Carbon::parse('2026-08-04 01:30:00', 'UTC'));
        $this->managerUser->notify(new \App\Notifications\CompanyAnnouncement(1, 'Heads up', 'Body'));

        $token = $this->managerUser->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.created_at', '2026-08-03T21:30:00-04:00');
    }

    // -------------------------------------------------------------------------
    // "Today" in the evening
    // -------------------------------------------------------------------------

    public function test_the_evening_summary_counts_the_day_that_is_still_today_in_new_york(): void
    {
        $this->travelTo(Carbon::parse('2026-08-04 01:30:00', 'UTC'));   // 21:30 on the 3rd in New York
        $this->punchAt('2026-08-03 09:02:00');

        $summary = app(AttendanceService::class)->daySummary($this->company->id);

        $this->assertSame(1, $summary['present'], 'UTC is already on the 4th; New York is still on the 3rd.');
    }

    public function test_the_evening_leave_list_is_for_the_day_that_is_still_today(): void
    {
        $this->travelTo(Carbon::parse('2026-08-04 01:30:00', 'UTC'));
        $type = LeaveType::create(['company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL']);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03', 'end_date' => '2026-08-03',
            'days' => 1, 'status' => 'approved',
        ]);

        $this->assertTrue(app(LeaveService::class)->onLeaveOn($this->company->id)->has($this->report->id));
    }

    public function test_approved_leave_starting_tomorrow_can_still_be_withdrawn_this_evening(): void
    {
        // 21:30 on the 3rd in New York; the leave starts on the 4th, which a
        // UTC clock thinks has already begun.
        $this->travelTo(Carbon::parse('2026-08-04 01:30:00', 'UTC'));
        $type = LeaveType::create(['company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL']);

        $request = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-04', 'end_date' => '2026-08-04',
            'days' => 1, 'status' => 'approved',
        ]);

        $this->assertTrue($request->fresh()->isCancellable());

        // And once it is the 4th in New York, it has started.
        $this->travelTo(Carbon::parse('2026-08-04 05:00:00', 'UTC'));
        $this->assertFalse($request->fresh()->isCancellable());
    }

    // -------------------------------------------------------------------------
    // The morning digest
    // -------------------------------------------------------------------------

    public function test_the_late_digest_waits_for_half_past_ten_in_the_company_zone(): void
    {
        Notification::fake();
        $hr = User::create([
            'name' => 'Hana', 'email' => 'hana@test.local', 'password' => 'password',
            'company_id' => $this->company->id,
        ]);
        $hr->assignRole('hr');

        // 10:30 UTC is 06:30 in New York: nobody has arrived.
        $this->travelTo(Carbon::parse('2026-08-03 10:30:00', 'UTC'));
        $this->punchAt('2026-08-03 06:20:00', 'in', 'late');
        $this->artisan('attendance:report-late')->assertSuccessful();
        Notification::assertNothingSent();

        // 14:30 UTC is 10:30 in New York.
        $this->travelTo(Carbon::parse('2026-08-03 14:30:00', 'UTC'));
        $this->artisan('attendance:report-late')->assertSuccessful();
        Notification::assertSentTo($hr, \App\Notifications\LateArrivalsDigest::class);
    }
}
