<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A7.14 — payroll-ready hours export.
 *
 * The figures come from the same overtime path the rest of Phase 6 uses, so
 * what is really under test here is the presentation contract payroll depends
 * on: decimal hours that can be summed, regular and overtime that add up to the
 * total, and a row for every active employee including the ones with nothing.
 */
class PayrollReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
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

        // 09:00–17:00 with a 30-minute unpaid break → 450 scheduled minutes.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
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
            'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    /** @param array<int, array{0: string, 1: string}> $punches */
    private function day(string $date, array $punches, ?Employee $for = null): void
    {
        $employee = $for ?? $this->employee;

        foreach ($punches as [$type, $time]) {
            AttendanceLog::create([
                'company_id'  => $employee->company_id,
                'employee_id' => $employee->id,
                'office_id'   => $employee->office_id,
                'type'        => $type,
                'scanned_at'  => Carbon::parse("$date $time"),
                'work_date'   => $date,
                'status'      => 'ontime',
                'source'      => 'button',
            ]);
        }
    }

    private function report(): array
    {
        return app(ReportService::class)->payroll($this->company->id, '2026-08-01', '2026-08-31');
    }

    // -------------------------------------------------------------------------
    // The numbers
    // -------------------------------------------------------------------------

    public function test_hours_are_decimal_numbers_not_formatted_strings(): void
    {
        // Payroll multiplies these by a rate, and Excel has to be able to total
        // the column. "7h 30m" can do neither.
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        $row = $this->report()['rows'][0];

        $this->assertIsFloat($row['Total Hours']);
        $this->assertSame(7.5, $row['Total Hours']);
    }

    public function test_regular_and_overtime_add_up_to_the_total(): void
    {
        // 09:00–19:00 is 600 present, 570 paid once the break comes out: 450
        // regular (7.5h) and 120 overtime (2.0h).
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);

        $row = $this->report()['rows'][0];

        $this->assertSame(7.5, $row['Regular Hours']);
        $this->assertSame(2.0, $row['Overtime Hours']);
        $this->assertSame(9.5, $row['Total Hours']);
        $this->assertSame(
            $row['Total Hours'],
            round($row['Regular Hours'] + $row['Overtime Hours'], 2),
        );
    }

    public function test_the_unpaid_break_is_excluded(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'], ['break_end', '14:00:00'],
            ['out', '18:00:00'],
        ]);

        // 540 present, less a real 60-minute break.
        $this->assertSame(8.0, $this->report()['rows'][0]['Total Hours']);
    }

    public function test_a_day_with_no_check_out_pays_nothing_and_is_not_counted(): void
    {
        // Counting the day while paying zero hours would put a "1 day worked,
        // 0.00 hours" line in front of whoever runs payroll.
        $this->day('2026-08-03', [['in', '09:00:00']]);

        $row = $this->report()['rows'][0];

        $this->assertSame(0.0, $row['Total Hours']);
        $this->assertSame(0, $row['Days Worked']);
    }

    public function test_days_worked_counts_distinct_days(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '17:00:00']]);

        $this->assertSame(2, $this->report()['rows'][0]['Days Worked']);
    }

    // -------------------------------------------------------------------------
    // Who appears
    // -------------------------------------------------------------------------

    public function test_an_employee_with_no_attendance_still_gets_a_row(): void
    {
        // A missing employee reads as an export bug. An employee with zeros
        // reads as somebody who was absent, which is the fact being reported.
        $row = $this->report()['rows'][0];

        $this->assertSame('E1', $row['Code']);
        $this->assertSame(0.0, $row['Total Hours']);
        $this->assertGreaterThan(0, $row['Absent Days']);
    }

    public function test_inactive_employees_are_left_out(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E9', 'first_name' => 'Gone', 'last_name' => 'Away',
            'status' => 'inactive',
        ]);

        $codes = array_column($this->report()['rows'], 'Code');

        $this->assertContains('E1', $codes);
        $this->assertNotContains('E9', $codes);
    }

    public function test_another_companys_staff_never_appear(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        $this->assertNotContains('X1', array_column($this->report()['rows'], 'Code'));
    }

    public function test_a_voided_punch_is_not_paid(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);

        AttendanceLog::where('employee_id', $this->employee->id)
            ->where('type', 'out')->firstOrFail()
            ->void($this->hr, 'Recorded against the wrong person');

        // The stretch is left open, so there is no honest figure to pay.
        $this->assertSame(0.0, $this->report()['rows'][0]['Total Hours']);
    }

    // -------------------------------------------------------------------------
    // The screen
    // -------------------------------------------------------------------------

    public function test_hr_can_open_the_payroll_report(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);

        $this->actingAs($this->hr)
            ->get(route('reports.payroll', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Payroll Hours')
            ->assertSee('Ann Lee');
    }

    public function test_an_employee_cannot_open_the_payroll_report(): void
    {
        $this->actingAs($this->employee->user)
            ->get(route('reports.payroll'))
            ->assertForbidden();
    }

    public function test_the_excel_export_needs_the_export_permission(): void
    {
        // view-reports alone must not be enough to walk out with everyone's
        // hours in a spreadsheet.
        $this->actingAs($this->employee->user)
            ->get(route('reports.payroll', ['export' => 'excel']))
            ->assertForbidden();
    }
}
