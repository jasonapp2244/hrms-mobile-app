<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceScore;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approved leave is not absence.
 *
 * The summary tiles, the monthly score and the department report all measure
 * absence against the days the company actually works, with booked leave
 * accounted for rather than held against anyone.
 */
class LeaveAttendanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday, so the surrounding week is unambiguous. */
    protected const TODAY = '2026-08-05';

    protected Company $company;
    protected Office $office;
    protected LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse(self::TODAY . ' 10:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ', 'code' => 'HQ',
        ]);
        $this->type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);
    }

    protected function makeEmployee(string $name, string $code): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => $code, 'first_name' => $name, 'status' => 'active',
        ]);
    }

    protected function punchIn(Employee $employee, string $date, string $status = 'ontime'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'in',
            'scanned_at'  => $date . ' 09:00:00',
            'work_date'   => $date,
            'status'      => $status,
            'source'      => 'button',
        ]);
    }

    protected function approvedLeave(Employee $employee, string $from, string $to, array $attrs = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'company_id'    => $this->company->id,
            'employee_id'   => $employee->id,
            'leave_type_id' => $this->type->id,
            'start_date'    => $from,
            'end_date'      => $to,
            'days'          => 1,
            'status'        => 'approved',
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

    // ---- the day summary ----

    public function test_approved_leave_is_reported_as_leave_not_absence(): void
    {
        $present = $this->makeEmployee('Pia', 'E1');
        $off     = $this->makeEmployee('Omar', 'E2');
        $this->makeEmployee('Ash', 'E3');   // genuinely unaccounted for

        $this->punchIn($present, self::TODAY);
        $this->approvedLeave($off, self::TODAY, self::TODAY);

        $summary = app(AttendanceService::class)->daySummary($this->company->id);

        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['on_leave']);
        // The regression this guards: Omar booked the day off and would
        // previously have been reported absent alongside Ash.
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(3, $summary['total']);
    }

    public function test_the_tiles_never_sum_past_the_headcount(): void
    {
        // Somebody on approved leave who comes in anyway is present, not both.
        $keen = $this->makeEmployee('Kim', 'E1');
        $this->approvedLeave($keen, self::TODAY, self::TODAY);
        $this->punchIn($keen, self::TODAY);

        $summary = app(AttendanceService::class)->daySummary($this->company->id);

        $this->assertSame(1, $summary['present']);
        $this->assertSame(0, $summary['on_leave']);
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(
            $summary['total'],
            $summary['present'] + $summary['on_leave'] + $summary['absent'],
        );
    }

    public function test_pending_leave_does_not_excuse_an_absence(): void
    {
        // Only an approved absence is an accounted-for one.
        $employee = $this->makeEmployee('Pat', 'E1');
        $this->approvedLeave($employee, self::TODAY, self::TODAY, ['status' => 'pending']);

        $summary = app(AttendanceService::class)->daySummary($this->company->id);

        $this->assertSame(0, $summary['on_leave']);
        $this->assertSame(1, $summary['absent']);
    }

    public function test_cancelled_leave_does_not_excuse_an_absence(): void
    {
        $employee = $this->makeEmployee('Cal', 'E1');
        $this->approvedLeave($employee, self::TODAY, self::TODAY, ['status' => 'cancelled']);

        $summary = app(AttendanceService::class)->daySummary($this->company->id);

        $this->assertSame(0, $summary['on_leave']);
        $this->assertSame(1, $summary['absent']);
    }

    public function test_a_multi_day_absence_covers_every_day_in_its_range(): void
    {
        $employee = $this->makeEmployee('Mel', 'E1');
        $this->approvedLeave($employee, '2026-08-03', '2026-08-07');

        $service = app(AttendanceService::class);

        $this->assertSame(1, $service->daySummary($this->company->id, '2026-08-03')['on_leave']);
        $this->assertSame(1, $service->daySummary($this->company->id, '2026-08-07')['on_leave']);
        // Outside the range they are simply unaccounted for again.
        $this->assertSame(0, $service->daySummary($this->company->id, '2026-08-10')['on_leave']);
    }

    // ---- leave dates ----

    public function test_weekends_inside_a_leave_range_are_not_leave_days(): void
    {
        // Mon 3rd to Sun 9th: five working days off, not seven.
        $employee = $this->makeEmployee('Wen', 'E1');
        $this->approvedLeave($employee, '2026-08-03', '2026-08-09');

        $dates = app(LeaveService::class)
            ->leaveDatesByEmployee($this->company->id, '2026-08-01', '2026-08-31');

        $this->assertCount(5, $dates[$employee->id]);
        $this->assertNotContains('2026-08-08', $dates[$employee->id]);   // Saturday
    }

    public function test_holidays_inside_a_leave_range_are_not_leave_days(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day', 'date' => '2026-08-05',
        ]);

        $employee = $this->makeEmployee('Hal', 'E1');
        $this->approvedLeave($employee, '2026-08-03', '2026-08-07');

        $dates = app(LeaveService::class)
            ->leaveDatesByEmployee($this->company->id, '2026-08-01', '2026-08-31');

        $this->assertCount(4, $dates[$employee->id]);
        $this->assertNotContains('2026-08-05', $dates[$employee->id]);
    }

    public function test_leave_dates_are_clamped_to_the_window_asked_for(): void
    {
        $employee = $this->makeEmployee('Cla', 'E1');
        $this->approvedLeave($employee, '2026-07-27', '2026-08-07');

        $dates = app(LeaveService::class)
            ->leaveDatesByEmployee($this->company->id, '2026-08-01', '2026-08-31');

        // Only the August half of the range: Mon 3rd to Fri 7th.
        $this->assertCount(5, $dates[$employee->id]);
        $this->assertSame('2026-08-03', $dates[$employee->id][0]);
    }

    // ---- the monthly score ----

    public function test_the_monthly_score_no_longer_reports_leave_as_absence(): void
    {
        // August 2026 has 21 working days. Present for 5, on leave for 5,
        // leaving 11 genuinely unaccounted for.
        $employee = $this->makeEmployee('Sco', 'E1');

        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            $this->punchIn($employee, $date);
        }
        $this->approvedLeave($employee, '2026-08-10', '2026-08-14');

        app(AttendanceService::class)->computeMonthlyScore($employee, '2026-08');

        $score = AttendanceScore::first();
        $this->assertSame(5, $score->present_days);
        $this->assertSame(5, $score->leave_days);
        $this->assertSame(11, $score->absent_count);
    }

    public function test_absence_is_measured_against_working_days_only(): void
    {
        // Nothing at all in August: 21 working days, all absent — not 31.
        $employee = $this->makeEmployee('Non', 'E1');

        app(AttendanceService::class)->computeMonthlyScore($employee, '2026-08');

        $this->assertSame(21, AttendanceScore::first()->absent_count);
    }

    public function test_a_holiday_is_not_an_absence(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day', 'date' => '2026-08-05',
        ]);

        $employee = $this->makeEmployee('Hol', 'E1');

        app(AttendanceService::class)->computeMonthlyScore($employee, '2026-08');

        $this->assertSame(20, AttendanceScore::first()->absent_count);
    }

    public function test_a_day_both_worked_and_booked_off_is_counted_once(): void
    {
        // The double-subtraction trap: present 21 and on leave 1 would drive the
        // count below zero if the two were simply added and subtracted.
        $employee = $this->makeEmployee('Dup', 'E1');

        foreach (app(LeaveService::class)->workingDatesBetween($this->company, '2026-08-01', '2026-08-31') as $date) {
            $this->punchIn($employee, $date);
        }
        $this->approvedLeave($employee, '2026-08-05', '2026-08-05');

        app(AttendanceService::class)->computeMonthlyScore($employee, '2026-08');

        $score = AttendanceScore::first();
        $this->assertSame(21, $score->present_days);
        $this->assertSame(0, $score->absent_count);
    }

    // ---- screens ----

    public function test_the_attendance_overview_names_who_is_off(): void
    {
        $off = $this->makeEmployee('Omar', 'E1');
        $this->approvedLeave($off, self::TODAY, self::TODAY);

        $this->actingAs($this->staff('hr'))
            ->get('/attendance')
            ->assertOk()
            ->assertSee('Off Today')
            ->assertSee('Omar')
            ->assertSee('Annual Leave');
    }

    public function test_the_overview_hides_the_off_today_panel_when_nobody_is_off(): void
    {
        $this->makeEmployee('Ash', 'E1');

        $this->actingAs($this->staff('hr'))
            ->get('/attendance')
            ->assertOk()
            ->assertDontSee('Off Today');
    }

    public function test_the_dashboard_shows_an_on_leave_tile(): void
    {
        $off = $this->makeEmployee('Omar', 'E1');
        $this->approvedLeave($off, self::TODAY, self::TODAY);

        $this->actingAs($this->staff('admin'))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('On Leave Today');
    }

    public function test_the_department_report_separates_leave_from_absence(): void
    {
        $employee = $this->makeEmployee('Dep', 'E1');
        $this->approvedLeave($employee, '2026-08-03', '2026-08-07');

        $this->actingAs($this->staff('hr'))
            ->get('/reports/department?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertSee('Leave (emp-days)')
            ->assertSee('Absent (emp-days)');
    }

    public function test_an_employee_on_leave_is_told_they_are_not_absent(): void
    {
        $user = User::create([
            'name' => 'Omar', 'email' => 'omar@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        $employee = $this->makeEmployee('Omar', 'E1');
        $employee->update(['user_id' => $user->id]);
        $this->approvedLeave($employee, self::TODAY, self::TODAY);

        $this->actingAs($user)
            ->get('/employee/dashboard')
            ->assertOk()
            ->assertSee('You are not marked absent');
    }
}
