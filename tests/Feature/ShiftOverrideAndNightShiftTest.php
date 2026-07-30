<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-employee shift overrides, and shifts that run past midnight.
 *
 * A night shift was previously judged against the calendar day the punch landed
 * on, which split one stretch of work across two dates and measured the clock-out
 * against the wrong end time.
 */
class ShiftOverrideAndNightShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $dayShift;
    protected Shift $nightShift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ', 'code' => 'HQ',
        ]);

        $this->dayShift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day', 'code' => 'D',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 60, 'late_grace_minutes' => 15,
        ]);

        $this->nightShift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night', 'code' => 'N',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Operations',
            'shift_id' => $this->dayShift->id,
        ]);
    }

    protected function makeEmployee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id'    => $this->company->id,
            'office_id'     => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . uniqid(),
            'first_name'    => 'Ann',
            'status'        => 'active',
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

    /** Punch at a wall-clock moment and return the stored log. */
    protected function punchAt(Employee $employee, string $moment): AttendanceLog
    {
        $this->travelTo(Carbon::parse($moment));

        return app(AttendanceService::class)
            ->record($employee, $this->office, ['source' => 'button'])['log'];
    }

    // ================= shift resolution =================

    public function test_an_employee_inherits_their_departments_shift(): void
    {
        $employee = $this->makeEmployee();

        $this->assertSame($this->dayShift->id, $employee->shift->id);
        $this->assertFalse($employee->hasShiftOverride());
    }

    public function test_an_employees_own_shift_wins_over_the_departments(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->assertSame($this->nightShift->id, $employee->shift->id);
        $this->assertTrue($employee->hasShiftOverride());
    }

    public function test_clearing_the_override_falls_back_to_the_department(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $employee->update(['shift_id' => null]);

        $this->assertSame($this->dayShift->id, $employee->fresh()->shift->id);
    }

    public function test_an_employee_with_no_department_and_no_override_has_no_shift(): void
    {
        $employee = $this->makeEmployee(['department_id' => null]);

        $this->assertNull($employee->shift);
    }

    public function test_an_override_survives_the_department_shift_changing(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        // Moving the whole team must not move the person singled out.
        $this->department->update(['shift_id' => null]);

        $this->assertSame($this->nightShift->id, $employee->fresh()->shift->id);
    }

    public function test_the_override_can_be_set_through_the_employee_form(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->staff('hr'))
            ->put("/employees/{$employee->id}", [
                'first_name' => 'Ann',
                'status'     => 'active',
                'work_mode'  => 'office',
                'shift_id'   => $this->nightShift->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->nightShift->id, $employee->fresh()->shift_id);
    }

    public function test_the_override_can_be_cleared_through_the_employee_form(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->actingAs($this->staff('hr'))
            ->put("/employees/{$employee->id}", [
                'first_name' => 'Ann',
                'status'     => 'active',
                'work_mode'  => 'office',
                'shift_id'   => '',
            ])
            ->assertRedirect();

        $this->assertNull($employee->fresh()->shift_id);
    }

    // ================= the shift model =================

    public function test_a_shift_ending_after_it_starts_does_not_cross_midnight(): void
    {
        $this->assertFalse($this->dayShift->crossesMidnight());
    }

    public function test_a_shift_ending_before_it_starts_crosses_midnight(): void
    {
        $this->assertTrue($this->nightShift->crossesMidnight());
    }

    public function test_paid_minutes_exclude_the_break(): void
    {
        // 09:00–17:00 is eight hours, less a one-hour break.
        $this->assertSame(420, $this->dayShift->workingMinutes());
        $this->assertSame('7h', $this->dayShift->working_hours);
    }

    public function test_paid_minutes_span_midnight_correctly(): void
    {
        // 22:00–06:00 is eight hours across the date boundary, less 30 minutes.
        // Measured naively it would come out negative.
        $this->assertSame(450, $this->nightShift->workingMinutes());
        $this->assertSame('7h 30m', $this->nightShift->working_hours);
    }

    // ================= night shift attendance =================

    public function test_a_night_clock_in_and_the_morning_clock_out_share_a_work_date(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $in  = $this->punchAt($employee, '2026-08-03 22:00:00');
        $out = $this->punchAt($employee, '2026-08-04 06:05:00');

        // The regression this guards: the clock-out landing on the 4th would
        // split one night's work across two dates, leaving the employee looking
        // absent on the 3rd and half-present on the 4th.
        $this->assertSame('2026-08-03', $in->work_date->toDateString());
        $this->assertSame('2026-08-03', $out->work_date->toDateString());
        $this->assertSame('in', $in->type);
        $this->assertSame('out', $out->type);
    }

    public function test_a_night_worker_arriving_on_time_is_not_late(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->assertSame('ontime', $this->punchAt($employee, '2026-08-03 22:10:00')->status);
    }

    public function test_a_night_worker_arriving_after_midnight_is_late(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        // Two hours after a 22:00 start, even though the calendar day has rolled.
        $this->assertSame('late', $this->punchAt($employee, '2026-08-04 00:30:00')->status);
    }

    public function test_a_night_worker_leaving_at_the_end_of_shift_is_not_early(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->punchAt($employee, '2026-08-03 22:00:00');

        $this->assertSame('ontime', $this->punchAt($employee, '2026-08-04 06:00:00')->status);
    }

    public function test_a_night_worker_leaving_before_midnight_is_early(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->punchAt($employee, '2026-08-03 22:00:00');

        // Measured against the wrong day this read as on time, because 23:00 is
        // after 06:00 on the same date.
        $this->assertSame('early_leave', $this->punchAt($employee, '2026-08-03 23:00:00')->status);
    }

    public function test_a_night_worker_leaving_before_the_shift_ends_is_early(): void
    {
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->punchAt($employee, '2026-08-03 22:00:00');

        $this->assertSame('early_leave', $this->punchAt($employee, '2026-08-04 04:00:00')->status);
    }

    public function test_the_day_shift_is_unaffected(): void
    {
        $employee = $this->makeEmployee();

        $this->assertSame('ontime', $this->punchAt($employee, '2026-08-03 09:05:00')->status);
        $this->assertSame('2026-08-03', AttendanceLog::first()->work_date->toDateString());
    }

    public function test_a_day_worker_arriving_after_the_grace_period_is_late(): void
    {
        $employee = $this->makeEmployee();

        $this->assertSame('late', $this->punchAt($employee, '2026-08-03 09:30:00')->status);
    }

    public function test_the_override_is_what_judges_the_punch(): void
    {
        // On the department's day shift 22:00 would be wildly late; on their own
        // night shift it is exactly on time.
        $employee = $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->assertSame('ontime', $this->punchAt($employee, '2026-08-03 22:00:00')->status);

        $other = $this->makeEmployee(['employee_code' => 'E-DAY']);
        $this->assertSame('late', $this->punchAt($other, '2026-08-03 22:00:00')->status);
    }

    // ================= the roster =================

    public function test_the_roster_marks_someone_on_approved_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        $employee = $this->makeEmployee(['first_name' => 'Omar']);
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave', 'days_per_year' => 20,
        ]);
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-07', 'days' => 5, 'status' => 'approved',
        ]);

        // Asserted on the data the cells are built from, not on the words:
        // the legend also says "On Leave", so assertSee alone would pass even
        // with every cell wrong.
        $response = $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=2026-08-03')
            ->assertOk();

        $onLeave = $response->viewData('onLeave');

        // Previously these cells said "Absent" — the roster contradicting the
        // leave register sitting next to it.
        $this->assertTrue($onLeave[$employee->id]->has('2026-08-03'));
        $this->assertTrue($onLeave[$employee->id]->has('2026-08-07'));
        // The weekend inside the range is not leave; it was never charged.
        $this->assertFalse($onLeave[$employee->id]->has('2026-08-08'));
    }

    public function test_the_roster_marks_a_company_holiday(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->makeEmployee();

        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day', 'date' => '2026-08-05',
        ]);

        $response = $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=2026-08-03')
            ->assertOk();

        $this->assertTrue($response->viewData('holidays')->has('2026-08-05'));
        $this->assertFalse($response->viewData('holidays')->has('2026-08-06'));
    }

    public function test_the_roster_honours_a_custom_company_weekend(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->makeEmployee();

        // Friday/Saturday weekend — the roster used to hardcode Sat/Sun.
        $this->company->update(['settings' => ['weekend_days' => [5, 6]]]);

        $response = $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=2026-08-03')
            ->assertOk();

        // Carbon numbers the week Sunday=0 … Saturday=6, so Sunday is now a
        // working day and Friday is not.
        $this->assertSame([5, 6], $response->viewData('weekend'));
    }

    public function test_the_roster_defaults_to_a_saturday_sunday_weekend(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->makeEmployee();

        $response = $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=2026-08-03')
            ->assertOk();

        $this->assertSame([0, 6], $response->viewData('weekend'));
    }

    public function test_the_roster_flags_an_employee_on_their_own_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->makeEmployee(['shift_id' => $this->nightShift->id]);

        $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=2026-08-03')
            ->assertOk()
            ->assertSee('own shift');
    }
}
