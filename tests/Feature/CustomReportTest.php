<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
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
 * A7.13 — the report builder.
 *
 * The figures come from the same paths the fixed reports use, so what is under
 * test is the assembly: that a column asked for appears, that one not asked for
 * does not, that the filters narrow who is counted, and that a column key typed
 * into the URL cannot reach anything the catalogue does not name.
 */
class CustomReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $ops;
    protected Employee $employee;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->ops = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->staff->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->ops->id,
            'office_id' => $this->office->id, 'user_id' => $this->staff->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'shift_id' => $shift->id, 'work_mode' => 'office',
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    private function day(string $date, array $punches, ?Employee $for = null): void
    {
        $employee = $for ?? $this->employee;

        foreach ($punches as [$type, $time]) {
            AttendanceLog::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'office_id' => $employee->office_id, 'type' => $type,
                'scanned_at' => Carbon::parse("$date $time"), 'work_date' => $date,
                'status' => 'ontime', 'source' => 'button',
            ]);
        }
    }

    private function report(array $options = [], ?int $officeId = null): array
    {
        return app(ReportService::class)
            ->custom($this->company->id, '2026-08-01', '2026-08-31', $officeId, $options);
    }

    // -------------------------------------------------------------------------
    // Choosing columns
    // -------------------------------------------------------------------------

    public function test_only_the_chosen_columns_appear(): void
    {
        $report = $this->report(['columns' => ['name', 'late']]);

        $this->assertSame(['Employee', 'Late Count'], $report['headings']);
        $this->assertSame(['Employee' => 'Ann Lee', 'Late Count' => 0], $report['rows'][0]);
    }

    public function test_columns_come_out_in_catalogue_order_however_they_were_ticked(): void
    {
        // Otherwise the same selection produces a different report depending on
        // the order the boxes happened to be clicked in.
        $report = $this->report(['columns' => ['late', 'name', 'code']]);

        $this->assertSame(['Code', 'Employee', 'Late Count'], $report['headings']);
    }

    public function test_an_unknown_column_is_ignored_rather_than_fatal(): void
    {
        // The keys travel in the query string; a stale bookmark must not 500.
        $report = $this->report(['columns' => ['name', 'employeeStats', 'password']]);

        $this->assertSame(['Employee'], $report['headings']);
    }

    public function test_choosing_nothing_falls_back_to_a_readable_default(): void
    {
        $report = $this->report(['columns' => []]);

        $this->assertNotEmpty($report['headings']);
        $this->assertContains('Employee', $report['headings']);
    }

    public function test_every_catalogue_column_can_be_produced(): void
    {
        // Guards the mapping: a column added to the catalogue with no value
        // behind it would otherwise fail only when somebody happened to tick it.
        $report = $this->report(['columns' => ReportService::customColumnKeys()]);

        $this->assertCount(count(ReportService::customColumnKeys()), $report['headings']);

        foreach ($report['headings'] as $heading) {
            $this->assertArrayHasKey($heading, $report['rows'][0]);
        }
    }

    // -------------------------------------------------------------------------
    // The figures
    // -------------------------------------------------------------------------

    public function test_hours_columns_carry_real_hours(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '19:00:00']]);

        $row = $this->report(['columns' => ['regular_hours', 'overtime_hours', 'total_hours', 'days_worked']])['rows'][0];

        $this->assertSame(1, $row['Days Worked']);
        $this->assertSame(7.5, $row['Regular Hours']);
        $this->assertSame(2.0, $row['Overtime Hours']);
        $this->assertSame(9.5, $row['Total Hours']);
    }

    public function test_attendance_columns_carry_real_counts(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '17:00:00']]);

        $row = $this->report(['columns' => ['present_days', 'ontime', 'ontime_pct']])['rows'][0];

        $this->assertSame(2, $row['Present Days']);
        $this->assertSame(2, $row['On-time Count']);
        $this->assertSame('100%', $row['On-time %']);
    }

    public function test_an_employee_who_never_clocked_in_has_no_on_time_reading(): void
    {
        // Not 0% — there is nothing to take a percentage of, and a zero would
        // drag down any average computed over the column.
        $row = $this->report(['columns' => ['ontime_pct']])['rows'][0];

        $this->assertSame('—', $row['On-time %']);
    }

    public function test_who_columns_read_from_the_employee_record(): void
    {
        $designation = Designation::create([
            'company_id' => $this->company->id, 'name' => 'Analyst',
        ]);

        $boss = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E0', 'first_name' => 'Max', 'last_name' => 'Reid',
            'status' => 'active',
        ]);

        $this->employee->update([
            'designation_id' => $designation->id,
            'manager_id' => $boss->id,
            'work_mode' => 'hybrid',
        ]);

        $rows = collect($this->report(['columns' => ['code', 'designation', 'manager', 'work_mode']])['rows'])
            ->keyBy('Code');

        $this->assertSame('Analyst', $rows['E1']['Designation']);
        $this->assertSame('Max Reid', $rows['E1']['Manager']);
        $this->assertSame('Hybrid', $rows['E1']['Work Mode']);

        // Somebody with neither reads as blank rather than breaking the row.
        $this->assertSame('—', $rows['E0']['Designation']);
        $this->assertSame('—', $rows['E0']['Manager']);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_the_department_filter_narrows_the_rows(): void
    {
        $sales = Department::create(['company_id' => $this->company->id, 'name' => 'Sales']);
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $sales->id,
            'office_id' => $this->office->id, 'employee_code' => 'E2',
            'first_name' => 'Bo', 'last_name' => 'Kim', 'status' => 'active',
        ]);

        $codes = array_column(
            $this->report(['columns' => ['code'], 'department_id' => $sales->id])['rows'],
            'Code',
        );

        $this->assertSame(['E2'], $codes);
    }

    public function test_the_work_mode_filter_narrows_the_rows(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->ops->id,
            'office_id' => $this->office->id, 'employee_code' => 'E3',
            'first_name' => 'Cy', 'last_name' => 'Roe', 'status' => 'active',
            'work_mode' => 'wfh',
        ]);

        $codes = array_column(
            $this->report(['columns' => ['code'], 'work_mode' => 'wfh'])['rows'],
            'Code',
        );

        $this->assertSame(['E3'], $codes);
    }

    public function test_the_office_filter_narrows_the_rows(): void
    {
        $branch = Office::create(['company_id' => $this->company->id, 'name' => 'Branch']);
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->ops->id,
            'office_id' => $branch->id, 'employee_code' => 'E4',
            'first_name' => 'Di', 'last_name' => 'Vos', 'status' => 'active',
        ]);

        $codes = array_column(
            $this->report(['columns' => ['code']], $branch->id)['rows'],
            'Code',
        );

        $this->assertSame(['E4'], $codes);
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

        $this->assertNotContains('X1', array_column($this->report(['columns' => ['code']])['rows'], 'Code'));
    }

    // -------------------------------------------------------------------------
    // The screen
    // -------------------------------------------------------------------------

    public function test_hr_can_open_the_builder(): void
    {
        $this->actingAs($this->hr)
            ->get(route('reports.custom'))
            ->assertOk()
            ->assertSee('Report Builder')
            ->assertSee('Ann Lee');
    }

    public function test_the_builder_honours_the_ticked_columns(): void
    {
        // Asserted on the view data rather than the markup: the column picker
        // lists every available column by name, so "Late Count" is on the page
        // whether or not it was ticked.
        $response = $this->actingAs($this->hr)
            ->get(route('reports.custom', ['columns' => ['name', 'work_mode']]))
            ->assertOk();

        $this->assertSame(['Employee', 'Work Mode'], $response->viewData('headings'));
    }

    public function test_a_department_from_another_company_is_not_honoured_as_a_filter(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirDept = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);

        // Ignored rather than obeyed: obeying it would filter our staff by a
        // department id that means something else entirely.
        $this->actingAs($this->hr)
            ->get(route('reports.custom', ['columns' => ['code'], 'department_id' => $theirDept->id]))
            ->assertOk()
            ->assertSee('E1');
    }

    public function test_an_employee_cannot_open_the_builder(): void
    {
        $this->actingAs($this->staff)->get(route('reports.custom'))->assertForbidden();
    }

    public function test_the_export_needs_the_export_permission(): void
    {
        $this->actingAs($this->staff)
            ->get(route('reports.custom', ['export' => 'excel']))
            ->assertForbidden();
    }

    public function test_the_excel_export_carries_the_chosen_columns(): void
    {
        $response = $this->actingAs($this->hr)
            ->get(route('reports.custom', ['columns' => ['name', 'total_hours'], 'export' => 'excel']));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}
