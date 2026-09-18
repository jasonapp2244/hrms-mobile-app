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
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A5.7 — the short-day floor on the nominal break.
 *
 * A break is a duty of the long day, not of every day. Without a floor the
 * minimum-break rule is wrong at the short end: an employee present for twenty
 * minutes is charged a full unpaid lunch, paid time clamps to zero, and a day
 * that was worked is reported as a day that was not.
 *
 * The floor has to be read on **both** sides. `scheduled` and `worked` are
 * subtracted from one another to get overtime, so a break taken out of one and
 * left in the other invents a break's worth of overtime on every short shift.
 * Half these tests exist only to hold those two together.
 */
class BreakThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
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
    }

    /** A shift with an hour of unpaid break, treated as a minimum. */
    private function shift(string $start, string $end, bool $minimum = true): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id, 'name' => "$start-$end",
            'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 60, 'break_is_paid' => false,
            'break_is_minimum' => $minimum, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);
    }

    private function roster(Shift $shift, string $date): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $shift->id, 'date' => $date, 'is_day_off' => false,
            'published_at' => now(),
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $pairs */
    private function day(string $date, array $pairs): void
    {
        foreach ($pairs as [$type, $time]) {
            AttendanceLog::create([
                'company_id'  => $this->company->id,
                'employee_id' => $this->employee->id,
                'office_id'   => $this->office->id,
                'type'        => $type,
                'scanned_at'  => Carbon::parse("$date $time"),
                'work_date'   => $date,
                'status'      => 'ontime',
                'source'      => 'mobile',
            ]);
        }
    }

    /** @return array<string, mixed> */
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
    // The rule itself
    // -------------------------------------------------------------------------

    public function test_a_day_too_short_to_owe_a_break_is_not_charged_for_one(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        // Twenty minutes present, against an hour of nominal break.
        $this->assertSame(0, $shift->settledBreakDeduction(0, false, 20));
    }

    public function test_a_long_day_is_still_charged_the_nominal_break(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        $this->assertSame(60, $shift->settledBreakDeduction(0, false, 480));
    }

    public function test_the_floor_is_the_boundary_it_says_it_is(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        // 360 is the default floor: one minute under owes nothing, the minute
        // itself owes the full break.
        $this->assertSame(0, $shift->settledBreakDeduction(0, false, 359));
        $this->assertSame(60, $shift->settledBreakDeduction(0, false, 360));
    }

    public function test_a_break_actually_taken_still_comes_off_a_short_day(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        // Below the floor the nominal does not apply, but a real break is a
        // real break: ten minutes taken is ten minutes off.
        $this->assertSame(10, $shift->settledBreakDeduction(10, true, 120));
    }

    public function test_the_deduction_never_exceeds_the_day_itself(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        // A punch pair longer than the stretch it sits in cannot take more off
        // than there was, or paid time goes negative and clamps to zero.
        $this->assertSame(30, $shift->settledBreakDeduction(45, true, 30));
    }

    public function test_a_paid_break_is_never_deducted_at_any_length(): void
    {
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Paid',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 60, 'break_is_paid' => true,
            'break_is_minimum' => true, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->assertSame(0, $shift->settledBreakDeduction(0, false, 20));
        $this->assertSame(0, $shift->settledBreakDeduction(0, false, 480));
    }

    public function test_omitting_the_day_length_keeps_the_answer_it_always_gave(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        // Callers with no day to measure — a policy preview, a shift listing —
        // must not silently change behaviour.
        $this->assertSame(60, $shift->settledBreakDeduction(0, false));
        $this->assertSame(60, $shift->settledBreakDeduction(10, true));
    }

    public function test_the_floor_is_configurable(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');

        config(['attendance.break.nominal_after_minutes' => 120]);
        $this->assertSame(60, $shift->settledBreakDeduction(0, false, 130));

        // Zero means every day owes it, which is the pre-floor behaviour.
        config(['attendance.break.nominal_after_minutes' => 0]);
        $this->assertSame(60, $shift->settledBreakDeduction(0, false, 5));
    }

    // -------------------------------------------------------------------------
    // Scheduled and worked, held together
    // -------------------------------------------------------------------------

    public function test_a_short_shift_keeps_its_whole_span_in_the_schedule(): void
    {
        $shift = $this->shift('09:00:00', '13:00:00');
        $this->roster($shift, '2026-03-02');

        // Four hours is under the floor, so the roster owes no break and the
        // scheduled figure is the whole span rather than 180.
        $this->assertSame(
            240,
            app(AttendanceService::class)->scheduledMinutesFor($this->employee->fresh(), '2026-03-02'),
        );
    }

    public function test_a_long_shift_still_loses_its_break_from_the_schedule(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');
        $this->roster($shift, '2026-03-03');

        $this->assertSame(
            420,
            app(AttendanceService::class)->scheduledMinutesFor($this->employee->fresh(), '2026-03-03'),
        );
    }

    public function test_a_short_shift_worked_in_full_earns_no_overtime(): void
    {
        $shift = $this->shift('09:00:00', '13:00:00');
        $this->roster($shift, '2026-03-04');
        $this->day('2026-03-04', [['in', '09:00:00'], ['out', '13:00:00']]);

        $result = $this->overtimeOn('2026-03-04');

        // The regression this whole file is for: reading the floor on one side
        // only would report 240 worked against 180 scheduled and hand out an
        // hour of overtime for a shift worked exactly as rostered.
        $this->assertSame(240, $result['worked']);
        $this->assertSame(240, $result['scheduled']);
        $this->assertSame(0, $result['overtime']);
    }

    public function test_a_short_day_no_longer_reports_as_worked_for_nothing(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');
        $this->roster($shift, '2026-03-05');

        // Twenty-three minutes, the length that used to clamp to zero because
        // an hour of nominal lunch was taken off a day that never had one.
        $this->day('2026-03-05', [['in', '09:00:00'], ['out', '09:23:00']]);

        $this->assertSame(23, $this->overtimeOn('2026-03-05')['worked']);
    }

    public function test_a_full_day_is_unaffected_by_the_floor(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');
        $this->roster($shift, '2026-03-06');
        $this->day('2026-03-06', [['in', '09:00:00'], ['out', '17:00:00']]);

        $result = $this->overtimeOn('2026-03-06');

        // 480 present, the nominal hour off, exactly the rostered 420.
        $this->assertSame(420, $result['worked']);
        $this->assertSame(420, $result['scheduled']);
        $this->assertSame(0, $result['overtime']);
    }

    public function test_the_minimum_rule_still_bites_on_a_long_day(): void
    {
        $shift = $this->shift('09:00:00', '17:00:00');
        $this->roster($shift, '2026-03-09');

        // A token one-minute break on a full day: the minimum rule is what
        // stops that buying back fifty-nine minutes, and the floor must not
        // have quietly disabled it.
        $this->day('2026-03-09', [
            ['in', '09:00:00'],
            ['break_start', '12:00:00'],
            ['break_end', '12:01:00'],
            ['out', '17:00:00'],
        ]);

        $this->assertSame(420, $this->overtimeOn('2026-03-09')['worked']);
    }

    public function test_a_night_shift_is_measured_across_midnight_before_the_floor(): void
    {
        // 22:00-06:00 is eight hours, but the two clock times subtract to minus
        // sixteen. The floor has to read the span the midnight rollover already
        // corrected, or every night shift falls under it and silently stops
        // owing its break.
        $shift = $this->shift('22:00:00', '06:00:00');
        $this->roster($shift, '2026-03-10');

        $this->assertSame(
            420,
            app(AttendanceService::class)->scheduledMinutesFor($this->employee->fresh(), '2026-03-10'),
        );
    }
}
