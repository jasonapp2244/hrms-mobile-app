<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Attendance History (A4.20) — the register, and one person's days inside it.
 *
 * **The arithmetic is the point of this file.** The screens themselves are thin;
 * what they show comes from `AttendanceService::dayRows()` and
 * `ReportService::attendanceHistory()`, and the failure that matters is a total
 * that does not reconcile with the days it was computed from. A summary nobody
 * can check against the punches is worse than no summary, because it is
 * believed.
 *
 * The break cases are here rather than in `BreakPunchTest` because that file
 * pins the *service* maths; these pin what a person reading the screen is told.
 * Two breaks in a day must sum, and one left open at check-out must cost
 * nothing — its length is unknown, and guessing it long silently cuts somebody's
 * hours.
 */
class AttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $shift;
    protected User $hr;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A fixed "now" so the default windows (this month, this week) are the
        // same every run. 2026-08-12 is a Wednesday.
        $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Morning Shift',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 60, 'break_is_paid' => false,
            'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Engineering',
            'shift_id' => $this->shift->id,
        ]);

        $designation = Designation::create([
            'company_id' => $this->company->id, 'name' => 'Software Engineer',
        ]);

        $this->hr = User::create([
            'name' => 'HR Manager', 'email' => 'hr@acme.test',
            'password' => Hash::make('secret-not-the-seeded-one'),
            'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');

        $this->employee = Employee::create([
            'company_id'     => $this->company->id,
            'department_id'  => $this->department->id,
            'office_id'      => $this->office->id,
            'designation_id' => $designation->id,
            'employee_code'  => 'EMP-0001',
            'first_name'     => 'Ann',
            'last_name'      => 'Lee',
            'status'         => 'active',
        ]);
    }

    /**
     * A day of punches.
     *
     * Copied rather than shared, which is the house style — `BreakPunchTest`
     * and `CustomReportTest` each carry their own.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $punches
     */
    private function day(string $date, array $punches, ?Employee $for = null): void
    {
        $for ??= $this->employee;

        foreach ($punches as $punch) {
            AttendanceLog::create([
                'company_id'  => $for->company_id,
                'employee_id' => $for->id,
                'office_id'   => $for->office_id,
                'type'        => $punch[0],
                'scanned_at'  => Carbon::parse("$date {$punch[1]}"),
                'work_date'   => $date,
                'status'      => $punch[2] ?? 'ontime',
                'source'      => 'button',
            ]);
        }
    }

    private function rows(string $from, string $to): \Illuminate\Support\Collection
    {
        return app(AttendanceService::class)->dayRows($this->employee, $from, $to);
    }

    private function rowFor(string $date, ?string $from = null, ?string $to = null): array
    {
        return $this->rows($from ?? $date, $to ?? $date)->firstWhere('date', $date);
    }

    // -------------------------------------------------------------------------
    // The day row — check-in, break, check-out
    // -------------------------------------------------------------------------

    public function test_a_day_reports_its_check_in_break_and_check_out(): void
    {
        // The worked example from the brief: in at 09:00, an hour's lunch, out
        // at 18:00 — nine hours present, one on lunch, eight paid.
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['break_end', '14:00:00'],
            ['out', '18:00:00'],
        ]);

        $row = $this->rowFor('2026-08-03');

        $this->assertSame('present', $row['status']);
        $this->assertSame('09:00', $row['first_in']->format('H:i'));
        $this->assertSame('13:00', $row['break_start']->format('H:i'));
        $this->assertSame('14:00', $row['break_end']->format('H:i'));
        $this->assertSame('18:00', $row['last_out']->format('H:i'));
        $this->assertSame(60, $row['break_minutes']);
        $this->assertSame(480, $row['worked_minutes']);
    }

    public function test_two_breaks_in_one_day_are_summed(): void
    {
        $this->day('2026-08-04', [
            ['in', '09:00:00'],
            ['break_start', '11:00:00'],
            ['break_end', '11:15:00'],
            ['break_start', '13:00:00'],
            ['break_end', '13:45:00'],
            ['out', '17:00:00'],
        ]);

        $row = $this->rowFor('2026-08-04');

        $this->assertSame(60, $row['break_minutes']);
        $this->assertSame(420, $row['worked_minutes']);
    }

    public function test_a_break_left_open_at_check_out_costs_nothing(): void
    {
        // Its length is unknown. Running it to the check-out would guess it long
        // and quietly cut the day in half.
        $this->day('2026-08-05', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['out', '17:00:00'],
        ]);

        $row = $this->rowFor('2026-08-05');

        $this->assertSame(0, $row['break_minutes']);
        $this->assertSame(480, $row['worked_minutes']);
        // The start is still shown, because it happened and somebody may need
        // to correct it.
        $this->assertSame('13:00', $row['break_start']->format('H:i'));
        $this->assertNull($row['break_end']);
    }

    public function test_a_day_never_clocked_out_reports_no_worked_time(): void
    {
        $this->day('2026-08-06', [['in', '09:00:00']]);

        $row = $this->rowFor('2026-08-06');

        $this->assertSame('present', $row['status']);
        $this->assertSame(0, $row['worked_minutes']);
        $this->assertNull($row['last_out']);
    }

    public function test_late_and_early_are_read_off_the_punches(): void
    {
        $this->day('2026-08-07', [
            ['in', '09:45:00', 'late'],
            ['out', '15:00:00', 'early_leave'],
        ]);

        $row = $this->rowFor('2026-08-07');

        $this->assertTrue($row['late']);
        $this->assertTrue($row['early_leave']);
    }

    public function test_a_day_with_no_punches_is_absent_and_a_weekend_is_not(): void
    {
        // 2026-08-08 is a Saturday, 2026-08-10 a Monday.
        $rows = $this->rows('2026-08-08', '2026-08-10');

        $this->assertSame('weekend', $rows->firstWhere('date', '2026-08-08')['status']);
        $this->assertSame('weekend', $rows->firstWhere('date', '2026-08-09')['status']);
        $this->assertSame('absent', $rows->firstWhere('date', '2026-08-10')['status']);
    }

    public function test_a_holiday_is_named_on_the_row(): void
    {
        Holiday::create([
            'company_id' => $this->company->id,
            'name' => 'Founders Day',
            'date' => '2026-08-11',
        ]);

        $row = $this->rowFor('2026-08-11');

        $this->assertSame('holiday', $row['status']);
        $this->assertSame('Founders Day', $row['holiday']);
    }

    public function test_the_range_includes_its_last_day(): void
    {
        // Trap 1. `work_date` is a date cast and on every engine but MySQL it is
        // stored as a midnight timestamp, so a `whereBetween` drops the last day
        // of every range — and the tests run on SQLite, where it is invisible.
        $this->day('2026-08-05', [['in', '09:00:00'], ['out', '17:00:00']]);

        $rows = $this->rows('2026-08-03', '2026-08-05');

        $this->assertCount(3, $rows);
        $this->assertSame('present', $rows->last()['status']);
    }

    // -------------------------------------------------------------------------
    // The register
    // -------------------------------------------------------------------------

    public function test_the_register_totals_reconcile_with_the_day_rows(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'], ['break_start', '13:00:00'],
            ['break_end', '14:00:00'], ['out', '18:00:00'],
        ]);
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '17:00:00']]);

        $rows = app(\App\Services\ReportService::class)
            ->attendanceHistory($this->company->id, '2026-08-03', '2026-08-04');

        $row = $rows->firstWhere(fn ($r) => $r['employee']->id === $this->employee->id);

        $this->assertSame(2, $row['present_days']);
        $this->assertSame(60, $row['break_minutes']);

        // The same two days read one at a time.
        $days = $this->rows('2026-08-03', '2026-08-04');
        $this->assertSame($days->sum('break_minutes'), $row['break_minutes']);
    }

    public function test_an_early_check_out_is_counted_once_per_day(): void
    {
        // Somebody who steps out and comes back leaves twice; only the last one
        // of the day is the time they went home.
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['out', '11:00:00', 'early_leave'],
            ['in', '12:00:00'],
            ['out', '15:00:00', 'early_leave'],
        ]);

        $rows = app(\App\Services\ReportService::class)
            ->attendanceHistory($this->company->id, '2026-08-03', '2026-08-03');

        $this->assertSame(1, $rows->first()['early_leave']);
    }

    public function test_the_search_narrows_to_one_person(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'employee_code' => 'EMP-0002',
            'first_name' => 'Bob', 'last_name' => 'Rowe', 'status' => 'active',
        ]);

        $service = app(\App\Services\ReportService::class);

        $this->assertCount(2, $service->attendanceHistory($this->company->id, '2026-08-03', '2026-08-04'));
        $this->assertCount(1, $service->attendanceHistory($this->company->id, '2026-08-03', '2026-08-04', ['q' => 'Rowe']));
        $this->assertCount(1, $service->attendanceHistory($this->company->id, '2026-08-03', '2026-08-04', ['q' => 'EMP-0001']));
        $this->assertCount(0, $service->attendanceHistory($this->company->id, '2026-08-03', '2026-08-04', ['q' => 'Nobody']));
    }

    // -------------------------------------------------------------------------
    // The screens
    // -------------------------------------------------------------------------

    public function test_hr_opens_the_register_and_sees_the_employee(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        $this->actingAs($this->hr)
            ->get(route('attendance.history', ['from' => '2026-08-01', 'to' => '2026-08-12']))
            ->assertOk()
            ->assertSee('Attendance History')
            ->assertSee('Ann Lee')
            ->assertSee('EMP-0001');
    }

    public function test_the_detail_page_lists_the_days(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'], ['break_start', '13:00:00'],
            ['break_end', '14:00:00'], ['out', '18:00:00'],
        ]);

        $this->actingAs($this->hr)
            ->get(route('attendance.history.show', [
                'employee' => $this->employee->id,
                'from' => '2026-08-03', 'to' => '2026-08-03',
            ]))
            ->assertOk()
            ->assertSee('Ann Lee')
            ->assertSee('Break Start')
            ->assertSee('8h 0m');
    }

    public function test_the_weekly_and_monthly_views_still_list_the_individual_days(): void
    {
        // A summary that hides the days it was computed from cannot be checked
        // against the punches.
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        foreach (['weekly', 'monthly', 'custom'] as $view) {
            $this->actingAs($this->hr)
                ->get(route('attendance.history.show', [
                    'employee' => $this->employee->id, 'view' => $view,
                    'from' => '2026-08-01', 'to' => '2026-08-12',
                ]))
                ->assertOk()
                ->assertSee('03 Aug 2026');
        }
    }

    public function test_an_ordinary_employee_cannot_open_the_register(): void
    {
        $staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('secret-not-the-seeded-one'),
            'company_id' => $this->company->id,
        ]);
        $staff->assignRole('employee');

        $this->actingAs($staff)->get(route('attendance.history'))->assertForbidden();
    }

    public function test_another_companys_employee_is_not_reachable(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirOffice = Office::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirDept = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'department_id' => $theirDept->id,
            'office_id' => $theirOffice->id, 'employee_code' => 'X-1',
            'first_name' => 'Someone', 'last_name' => 'Else', 'status' => 'active',
        ]);

        // Route-model binding does not know about companies, so this is the
        // only thing standing between an id typed into the address bar and
        // another company's attendance.
        $this->actingAs($this->hr)
            ->get(route('attendance.history.show', ['employee' => $theirs->id]))
            ->assertNotFound();

        $this->actingAs($this->hr)
            ->get(route('attendance.history'))
            ->assertOk()
            ->assertDontSee('Someone');
    }

    // -------------------------------------------------------------------------
    // Exports
    // -------------------------------------------------------------------------

    public function test_the_excel_export_needs_the_export_permission(): void
    {
        $this->hr->revokePermissionTo('export-reports');
        $this->hr->roles()->first()->revokePermissionTo('export-reports');
        $this->hr->forgetCachedPermissions();

        $this->actingAs($this->hr)
            ->get(route('attendance.history', ['export' => 'excel']))
            ->assertForbidden();
    }

    public function test_the_export_returns_a_spreadsheet(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        $response = $this->actingAs($this->hr)
            ->get(route('attendance.history', [
                'from' => '2026-08-01', 'to' => '2026-08-12', 'export' => 'excel',
            ]))
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_a_hostile_date_cannot_reach_the_export_filename(): void
    {
        // Both ends are concatenated into the download filename, and a filename
        // is a path — maatwebsite/excel wrote outside its configured disk for
        // exactly this reason through 3.1.69 (CVE-2026-84374).
        foreach (['../../../etc/passwd', '2026-08-01/../../x', "2026-08-01\0.php"] as $hostile) {
            $this->actingAs($this->hr)
                ->get(route('attendance.history', ['from' => $hostile, 'export' => 'excel']))
                ->assertSessionHasErrors('from');
        }
    }
}
