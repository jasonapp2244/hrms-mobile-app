<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A4.14 — overtime.
 *
 * The arithmetic is easy; the edge cases are the feature. A forgotten check-out
 * must not become a payroll claim, a day nobody was rostered has no schedule to
 * exceed, and packing up two minutes late must not earn overtime every day.
 * Those three are what these tests are mostly about.
 */
class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Shift $shift;
    protected Employee $employee;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        // 09:00–17:00 with a 30-minute unpaid break: 450 scheduled minutes.
        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'shift_id' => $this->shift->id,
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    /** @param array<int, array{0: string, 1: string}> $pairs type => time */
    private function day(string $date, array $pairs): \Illuminate\Support\Collection
    {
        foreach ($pairs as [$type, $time]) {
            $moment = Carbon::parse("$date $time");

            AttendanceLog::create([
                'company_id'  => $this->company->id,
                'employee_id' => $this->employee->id,
                'office_id'   => $this->office->id,
                'type'        => $type,
                'scanned_at'  => $moment,
                'work_date'   => $date,
                'status'      => 'ontime',
                'source'      => 'pwa',
            ]);
        }

        return AttendanceLog::where('employee_id', $this->employee->id)
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get();
    }

    private function overtimeOn(string $date): array
    {
        return app(AttendanceService::class)->overtimeFor(
            $this->employee->fresh(),
            $date,
            AttendanceLog::where('employee_id', $this->employee->id)
                ->whereDate('work_date', $date)
                ->orderBy('scanned_at')
                ->get(),
        );
    }

    // -------------------------------------------------------------------------
    // The schedule itself
    // -------------------------------------------------------------------------

    public function test_scheduled_minutes_subtract_the_unpaid_break(): void
    {
        // 09:00–17:00 is 480 minutes; less a 30-minute break is 450.
        $this->assertSame(
            450,
            app(AttendanceService::class)->scheduledMinutesFor($this->employee, '2026-08-03'),
        );
    }

    public function test_a_night_shift_is_measured_across_midnight(): void
    {
        $night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 0, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);
        $this->employee->update(['shift_id' => $night->id]);

        // Not a negative number, and not zero: eight hours.
        $this->assertSame(
            480,
            app(AttendanceService::class)->scheduledMinutesFor($this->employee->fresh(), '2026-08-03'),
        );
    }

    public function test_a_rostered_day_off_has_no_schedule(): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-08', 'is_day_off' => true,
        ]);

        // Null, not zero — "not expected in" and "expected in for no time" are
        // different, and overtime turns on the difference.
        $this->assertNull(
            app(AttendanceService::class)->scheduledMinutesFor($this->employee->fresh(), '2026-08-08'),
        );
    }

    // -------------------------------------------------------------------------
    // The ordinary cases
    // -------------------------------------------------------------------------

    public function test_a_normal_day_earns_no_overtime(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        // Present 09:00–17:00 is 480 minutes, but 30 of those are the shift's
        // unpaid break, so 450 were worked — exactly the schedule, nothing owed.
        //
        // This asserted 30 minutes of overtime until A4.15. Comparing present
        // time against a schedule that excludes the break handed everybody the
        // length of their lunch as overtime, every day.
        $result = $this->overtimeOn('2026-08-03');
        $this->assertSame(450, $result['worked']);
        $this->assertSame(0, $result['overtime']);
    }

    public function test_a_few_minutes_over_does_not_count(): void
    {
        // 460 worked against 450 scheduled: 10 minutes over, below the
        // threshold. Without this everyone earns overtime every single day.
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '16:40:00']]);

        $this->assertSame(0, $this->overtimeOn('2026-08-04')['overtime']);
    }

    public function test_the_whole_excess_is_owed_once_it_counts(): void
    {
        // 09:00–19:00 is 600 present, 570 worked once the unpaid break comes
        // out, so 120 over schedule. The threshold decides whether it counts,
        // not how much — reporting 105 here would quietly short every claim by
        // fifteen minutes.
        $this->day('2026-08-05', [['in', '09:00:00'], ['out', '19:00:00']]);

        $this->assertSame(120, $this->overtimeOn('2026-08-05')['overtime']);
    }

    public function test_working_under_schedule_is_never_negative(): void
    {
        $this->day('2026-08-06', [['in', '09:00:00'], ['out', '12:00:00']]);

        $this->assertSame(0, $this->overtimeOn('2026-08-06')['overtime']);
    }

    // -------------------------------------------------------------------------
    // The awkward ones
    // -------------------------------------------------------------------------

    public function test_a_day_nobody_was_rostered_is_all_overtime(): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-08', 'is_day_off' => true,
        ]);
        $this->day('2026-08-08', [['in', '10:00:00'], ['out', '14:00:00']]);

        $result = $this->overtimeOn('2026-08-08');
        $this->assertFalse($result['rostered']);
        $this->assertSame(240, $result['overtime']);
    }

    public function test_a_forgotten_check_out_earns_nothing(): void
    {
        // An open stretch on a past day. workedMinutes refuses to guess, so
        // overtime must not invent one either — an unclosed punch turning into
        // a payroll claim is the worst outcome this feature could have.
        $this->day('2026-08-07', [['in', '09:00:00']]);

        $result = $this->overtimeOn('2026-08-07');
        $this->assertSame(0, $result['worked']);
        $this->assertSame(0, $result['overtime']);
    }

    public function test_an_implausible_day_is_capped(): void
    {
        config(['attendance.overtime.daily_cap_minutes' => 120]);

        $this->day('2026-08-09', [['in', '00:00:00'], ['out', '23:00:00']]);

        $result = $this->overtimeOn('2026-08-09');
        $this->assertSame(120, $result['overtime']);
        $this->assertTrue($result['capped']);
    }

    public function test_a_voided_punch_does_not_earn_overtime(): void
    {
        $this->day('2026-08-10', [['in', '09:00:00'], ['out', '19:00:00']]);

        $late = AttendanceLog::where('employee_id', $this->employee->id)
            ->whereDate('work_date', '2026-08-10')->where('type', 'out')->firstOrFail();

        $late->void($this->hr, 'Recorded against the wrong person');

        // With the out struck out the stretch is open, so there is no honest
        // figure — and the global scope means the report never sees the row.
        $this->assertSame(0, $this->overtimeOn('2026-08-10')['overtime']);
    }

    public function test_unrostered_days_can_be_switched_off(): void
    {
        config(['attendance.overtime.count_unrostered_days' => false]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-08', 'is_day_off' => true,
        ]);
        $this->day('2026-08-08', [['in', '10:00:00'], ['out', '14:00:00']]);

        $this->assertSame(0, $this->overtimeOn('2026-08-08')['overtime']);
    }

    // -------------------------------------------------------------------------
    // The report
    // -------------------------------------------------------------------------

    public function test_the_report_totals_overtime_per_employee(): void
    {
        // Each day is present-time less the 30-minute unpaid break, against a
        // 450-minute schedule: 570 − 450 = 120, then 510 − 450 = 60.
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);   // 120 over
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '18:00:00']]);   // 60 over

        $report = app(ReportService::class)->overtime($this->company->id, '2026-08-01', '2026-08-31');

        $this->assertCount(1, $report['rows']);
        $this->assertSame('3h 0m', $report['rows'][0]['Overtime']);
        $this->assertSame(2, $report['rows'][0]['Days']);
    }

    public function test_employees_without_overtime_are_left_out(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '16:40:00']]);

        $report = app(ReportService::class)->overtime($this->company->id, '2026-08-01', '2026-08-31');

        $this->assertCount(0, $report['rows']);
    }

    public function test_the_report_flags_days_that_hit_the_cap(): void
    {
        config(['attendance.overtime.daily_cap_minutes' => 60]);
        $this->day('2026-08-03', [['in', '00:00:00'], ['out', '23:00:00']]);

        $report = app(ReportService::class)->overtime($this->company->id, '2026-08-01', '2026-08-31');

        // Surfaced, not swallowed: a capped day is usually a bad punch.
        $this->assertStringContainsString('daily cap', $report['subtitle']);
    }

    public function test_hr_can_open_the_overtime_report(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);

        $this->actingAs($this->hr)
            ->get(route('reports.overtime', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Overtime Report')
            ->assertSee('Ann Lee');
    }

    public function test_an_employee_cannot_open_the_overtime_report(): void
    {
        $this->actingAs($this->employee->user)
            ->get(route('reports.overtime'))
            ->assertForbidden();
    }
}
