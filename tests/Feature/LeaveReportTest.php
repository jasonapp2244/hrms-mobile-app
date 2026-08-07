<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A7.10 — the leave report.
 *
 * The arithmetic that matters here is the window: a report for August must
 * count the August half of a holiday booked across the month boundary, and no
 * more. Everything else — weekends, holidays, half days — follows the rules
 * leave was charged under, because a report that disagrees with the balance it
 * is reporting on is worse than no report.
 */
class LeaveReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected LeaveType $annual;
    protected LeaveType $sick;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);

        $this->annual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'is_active' => true,
        ]);

        $this->sick = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sick', 'code' => 'SL',
            'days_per_year' => 10, 'is_paid' => true, 'is_active' => true,
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
            'status' => 'active',
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    private function book(
        string $from,
        string $to,
        ?LeaveType $type = null,
        string $status = 'approved',
        bool $halfDay = false,
        ?Employee $for = null,
    ): LeaveRequest {
        $employee = $for ?? $this->employee;

        return LeaveRequest::create([
            'company_id'    => $employee->company_id,
            'employee_id'   => $employee->id,
            'leave_type_id' => ($type ?? $this->annual)->id,
            'start_date'    => $from,
            'end_date'      => $to,
            'days'          => 1,
            'is_half_day'   => $halfDay,
            'status'        => $status,
        ]);
    }

    /** August 2026 — the 1st and 2nd are a weekend, so it opens on Monday the 3rd. */
    private function report(string $from = '2026-08-01', string $to = '2026-08-31'): array
    {
        return app(ReportService::class)->leave($this->company->id, $from, $to);
    }

    private function row(array $report, string $code = 'E1'): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['Code'] === $code) {
                return $row;
            }
        }

        $this->fail("No row for employee $code in the leave report.");
    }

    // -------------------------------------------------------------------------
    // Counting days
    // -------------------------------------------------------------------------

    public function test_leave_days_inside_the_window_are_counted(): void
    {
        $this->book('2026-08-03', '2026-08-05');   // Mon–Wed

        $this->assertSame(3.0, $this->row($this->report())['Total Days']);
    }

    public function test_a_booking_across_the_month_boundary_is_split_by_the_window(): void
    {
        // Mon 31 Aug through Fri 4 Sep. August owns one day of it; reading `days`
        // off the request would hand all five to whichever month it started in.
        $this->book('2026-08-31', '2026-09-04');

        $this->assertSame(1.0, $this->row($this->report())['Total Days']);
        $this->assertSame(4.0, $this->row($this->report('2026-09-01', '2026-09-30'))['Total Days']);
    }

    public function test_weekends_are_not_counted(): void
    {
        // Fri 7th to Mon 10th: two working days, not four.
        $this->book('2026-08-07', '2026-08-10');

        $this->assertSame(2.0, $this->row($this->report())['Total Days']);
    }

    public function test_company_holidays_are_not_counted(): void
    {
        Holiday::create([
            'company_id' => $this->company->id,
            'name' => 'Founders Day',
            'date' => '2026-08-04',
        ]);

        $this->book('2026-08-03', '2026-08-05');

        $this->assertSame(2.0, $this->row($this->report())['Total Days']);
    }

    public function test_a_half_day_costs_half(): void
    {
        $this->book('2026-08-03', '2026-08-03', halfDay: true);

        $this->assertSame(0.5, $this->row($this->report())['Total Days']);
    }

    public function test_overlapping_bookings_of_different_types_both_count(): void
    {
        $this->book('2026-08-03', '2026-08-04', $this->annual);
        $this->book('2026-08-05', '2026-08-05', $this->sick);

        $row = $this->row($this->report());

        $this->assertSame(2.0, $row['Annual']);
        $this->assertSame(1.0, $row['Sick']);
        $this->assertSame(3.0, $row['Total Days']);
    }

    // -------------------------------------------------------------------------
    // The columns
    // -------------------------------------------------------------------------

    public function test_the_type_columns_are_the_companys_own_leave_types(): void
    {
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sabbatical', 'code' => 'SAB',
            'days_per_year' => 30, 'is_paid' => false, 'is_active' => true,
        ]);

        $this->assertContains('Sabbatical', $this->report()['headings']);
    }

    public function test_an_inactive_leave_type_gets_no_column(): void
    {
        $this->sick->update(['is_active' => false]);

        $this->assertNotContains('Sick', $this->report()['headings']);
    }

    public function test_every_row_carries_a_value_for_every_heading(): void
    {
        // The Excel and PDF exports both index rows by heading. A heading with
        // no matching key is an undefined-index crash on export, not a blank.
        $this->book('2026-08-03', '2026-08-04');

        $report = $this->report();

        foreach ($report['rows'] as $row) {
            foreach ($report['headings'] as $heading) {
                $this->assertArrayHasKey($heading, $row);
            }
        }
    }

    public function test_a_leave_type_named_like_a_fixed_column_does_not_overwrite_it(): void
    {
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Employee', 'code' => 'EMP',
            'days_per_year' => 5, 'is_paid' => true, 'is_active' => true,
        ]);

        $row = $this->row($this->report());

        $this->assertSame('Ann Lee', $row['Employee']);
        $this->assertContains('Employee (leave)', $this->report()['headings']);
    }

    // -------------------------------------------------------------------------
    // Pending, remaining, and who appears
    // -------------------------------------------------------------------------

    public function test_pending_days_are_reported_apart_from_approved_ones(): void
    {
        // Days not yet granted must not read as days taken — the point of the
        // column is to show cover that is about to be requested.
        $this->book('2026-08-03', '2026-08-04', status: 'pending');

        $row = $this->row($this->report());

        $this->assertSame(0.0, $row['Total Days']);
        $this->assertSame(2.0, $row['Pending']);
    }

    public function test_a_rejected_request_counts_as_neither(): void
    {
        $this->book('2026-08-03', '2026-08-04', status: 'rejected');

        $row = $this->row($this->report());

        $this->assertSame(0.0, $row['Total Days']);
        $this->assertSame(0.0, $row['Pending']);
    }

    public function test_remaining_is_the_unspent_entitlement_for_the_year(): void
    {
        LeaveBalance::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id, 'year' => 2026,
            'entitled_days' => 20, 'carried_forward' => 2, 'used_days' => 5,
        ]);

        $this->assertSame(17.0, $this->row($this->report())['Remaining']);
    }

    public function test_a_retired_types_leftover_days_are_not_offered_as_remaining(): void
    {
        LeaveBalance::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->sick->id, 'year' => 2026,
            'entitled_days' => 10, 'carried_forward' => 0, 'used_days' => 0,
        ]);

        $this->sick->update(['is_active' => false]);

        // Nothing bookable is left, so nothing remains.
        $this->assertSame(0.0, $this->row($this->report())['Remaining']);
    }

    public function test_an_employee_who_took_nothing_still_gets_a_row(): void
    {
        $row = $this->row($this->report());

        $this->assertSame(0.0, $row['Total Days']);
        $this->assertSame('Ann Lee', $row['Employee']);
    }

    public function test_inactive_employees_are_left_out(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E9', 'first_name' => 'Gone', 'last_name' => 'Away',
            'status' => 'inactive',
        ]);

        $this->assertNotContains('E9', array_column($this->report()['rows'], 'Code'));
    }

    public function test_another_companys_leave_never_appears(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $otherType = LeaveType::create([
            'company_id' => $other->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'is_active' => true,
        ]);
        $theirs = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        $this->book('2026-08-03', '2026-08-05', $otherType, for: $theirs);

        $report = $this->report();

        $this->assertNotContains('X1', array_column($report['rows'], 'Code'));
        $this->assertSame(0.0, $report['tiles'][1]['value']);
    }

    public function test_the_office_filter_narrows_the_report(): void
    {
        $branch = Office::create(['company_id' => $this->company->id, 'name' => 'Branch']);
        Employee::create([
            'company_id' => $this->company->id, 'office_id' => $branch->id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Kim',
            'status' => 'active',
        ]);

        $codes = array_column(
            app(ReportService::class)->leave($this->company->id, '2026-08-01', '2026-08-31', $branch->id)['rows'],
            'Code',
        );

        $this->assertSame(['E2'], $codes);
    }

    // -------------------------------------------------------------------------
    // The screen
    // -------------------------------------------------------------------------

    public function test_hr_can_open_the_leave_report(): void
    {
        $this->book('2026-08-03', '2026-08-05');

        $this->actingAs($this->hr)
            ->get(route('reports.leave', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Leave Report')
            ->assertSee('Ann Lee');
    }

    public function test_an_employee_cannot_open_the_leave_report(): void
    {
        $this->actingAs($this->employee->user)
            ->get(route('reports.leave'))
            ->assertForbidden();
    }

    public function test_the_excel_export_needs_the_export_permission(): void
    {
        $this->actingAs($this->employee->user)
            ->get(route('reports.leave', ['export' => 'excel']))
            ->assertForbidden();
    }
}
