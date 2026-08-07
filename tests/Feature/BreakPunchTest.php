<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
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
 * A4.15 — break in / break out punches.
 *
 * A break has to sit inside a check-in/check-out pair without disturbing it.
 * The failures worth guarding are the ones that would corrupt the day rather
 * than merely misreport it: a break read as a check-out, a second attendance
 * stretch opened by the punch after a break, and a break marker left with no
 * partner.
 *
 * Also covered here: the overtime comparison this feature corrects. Before it,
 * worked time included the unpaid break while scheduled time excluded it, so an
 * ordinary day manufactured overtime equal to the break for everybody.
 */
class BreakPunchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        // 09:00–17:00, 30-minute unpaid break → 450 scheduled minutes.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'shift_id' => $shift->id,
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $punches */
    private function day(string $date, array $punches): \Illuminate\Support\Collection
    {
        foreach ($punches as [$type, $time]) {
            AttendanceLog::create([
                'company_id'  => $this->company->id,
                'employee_id' => $this->employee->id,
                'office_id'   => $this->office->id,
                'type'        => $type,
                'scanned_at'  => Carbon::parse("$date $time"),
                'work_date'   => $date,
                'status'      => 'ontime',
                'source'      => 'button',
            ]);
        }

        return $this->logsFor($date);
    }

    private function logsFor(string $date): \Illuminate\Support\Collection
    {
        return AttendanceLog::where('employee_id', $this->employee->id)
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Worked time
    // -------------------------------------------------------------------------

    public function test_a_break_is_deducted_from_worked_time(): void
    {
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['break_end', '13:45:00'],
            ['out', '17:00:00'],
        ]);

        // 480 present, less a 45-minute break.
        $this->assertSame(435, app(AttendanceService::class)->workedMinutes($logs));
    }

    public function test_a_break_does_not_close_the_attendance_stretch(): void
    {
        // The morning and the afternoon are one stretch, not two attendances.
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['break_end', '14:00:00'],
            ['out', '17:00:00'],
        ]);

        $this->assertSame(420, app(AttendanceService::class)->workedMinutes($logs));
    }

    public function test_two_breaks_in_a_day_both_count(): void
    {
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '11:00:00'], ['break_end', '11:15:00'],
            ['break_start', '13:00:00'], ['break_end', '13:30:00'],
            ['out', '17:00:00'],
        ]);

        $this->assertSame(480 - 15 - 30, app(AttendanceService::class)->workedMinutes($logs));
    }

    public function test_a_break_left_open_at_check_out_is_discarded(): void
    {
        // Its length is unknown. Deducting it up to the check-out would guess it
        // long and quietly cut the employee's hours.
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['out', '17:00:00'],
        ]);

        $this->assertSame(480, app(AttendanceService::class)->workedMinutes($logs));
    }

    public function test_time_does_not_accrue_while_still_on_a_break(): void
    {
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
        ]);

        // "Worked so far" at 15:00, having been at lunch since 13:00.
        $this->assertSame(
            240,
            app(AttendanceService::class)->workedMinutes($logs, Carbon::parse('2026-08-03 15:00:00')),
        );
    }

    public function test_worked_time_is_never_negative(): void
    {
        // A break longer than the stretch containing it — malformed data rather
        // than a real day, but it must not produce a negative total.
        $logs = $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '09:05:00'],
            ['break_end', '23:00:00'],
            ['out', '09:30:00'],
        ]);

        $this->assertGreaterThanOrEqual(0, app(AttendanceService::class)->workedMinutes($logs));
    }

    // -------------------------------------------------------------------------
    // The punch after a break
    // -------------------------------------------------------------------------

    public function test_the_next_press_after_a_break_is_a_check_out(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['break_end', '13:30:00'],
        ]);

        // Without break punches being excluded from the in/out inference, this
        // reads 'break_end' as the last punch and starts a second attendance.
        Carbon::setTestNow(Carbon::parse('2026-08-03 17:00:00', $this->company->tz()));

        $result = app(AttendanceService::class)->record($this->employee, $this->office);

        $this->assertSame('out', $result['type']);

        Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // Starting and ending a break
    // -------------------------------------------------------------------------

    public function test_a_break_cannot_start_before_checking_in(): void
    {
        $this->expectException(\RuntimeException::class);

        app(AttendanceService::class)->recordBreak($this->employee, $this->office);
    }

    public function test_the_service_alternates_start_and_end(): void
    {
        $this->day('2026-08-03', [['in', '09:00:00']]);
        Carbon::setTestNow(Carbon::parse('2026-08-03 13:00:00', $this->company->tz()));

        $service = app(AttendanceService::class);
        $this->assertSame('break_start', $service->recordBreak($this->employee, $this->office)['type']);

        Carbon::setTestNow(Carbon::parse('2026-08-03 13:30:00', $this->company->tz()));
        $this->assertSame('break_end', $service->recordBreak($this->employee, $this->office)['type']);

        Carbon::setTestNow();
    }

    public function test_checking_out_ends_any_open_break(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['out', '17:00:00'],
        ]);

        $state = app(AttendanceService::class)->breakState($this->employee, '2026-08-03');

        $this->assertFalse($state['clocked_in']);
        $this->assertFalse($state['on_break']);
    }

    public function test_the_portal_records_a_break(): void
    {
        // The check-in has to be created at 09:00 rather than "now", or its
        // created_at lands inside recentlyScanned()'s cooldown and the break is
        // rejected as a double press.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', $this->company->tz()));
        $this->day('2026-08-03', [['in', '09:00:00']]);

        Carbon::setTestNow(Carbon::parse('2026-08-03 13:00:00', $this->company->tz()));

        $this->actingAs($this->user)
            ->postJson(route('employee.break'))
            ->assertOk()
            ->assertJson(['ok' => true, 'type' => 'break_start']);

        $this->assertSame(1, AttendanceLog::where('type', 'break_start')->count());

        Carbon::setTestNow();
    }

    public function test_the_portal_refuses_a_break_off_the_clock(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('employee.break'))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AttendanceLog::count());
    }

    // -------------------------------------------------------------------------
    // Overtime: the comparison this feature corrects
    // -------------------------------------------------------------------------

    public function test_an_ordinary_day_no_longer_manufactures_overtime(): void
    {
        // 09:00–17:00 with no break punched. Worked reads 480, schedule is 450
        // because the shift's break is unpaid — so before A4.15 this reported
        // 30 minutes of overtime for a completely ordinary day, for everybody.
        $this->day('2026-08-03', [['in', '09:00:00'], ['out', '17:00:00']]);

        $result = app(AttendanceService::class)
            ->overtimeFor($this->employee, '2026-08-03', $this->logsFor('2026-08-03'));

        $this->assertSame(0, $result['overtime']);
    }

    public function test_punching_your_break_does_not_cost_you_overtime(): void
    {
        // Two employees, same hours, one presses the break button and one does
        // not. They must come out the same, or the button is a pay cut.
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'], ['break_end', '13:30:00'],
            ['out', '18:00:00'],
        ]);
        $punched = app(AttendanceService::class)
            ->overtimeFor($this->employee, '2026-08-03', $this->logsFor('2026-08-03'));

        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '18:00:00']]);
        $notPunched = app(AttendanceService::class)
            ->overtimeFor($this->employee, '2026-08-04', $this->logsFor('2026-08-04'));

        // 540 present, less the 30-minute break, against a 450 schedule.
        $this->assertSame(60, $punched['overtime']);
        $this->assertSame($punched['overtime'], $notPunched['overtime']);
    }

    public function test_a_long_break_reduces_overtime(): void
    {
        // Stayed until 18:00 but took two hours out: 540 present less 120 is
        // 420, under the 450 schedule, so nothing is owed.
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '12:00:00'], ['break_end', '14:00:00'],
            ['out', '18:00:00'],
        ]);

        $result = app(AttendanceService::class)
            ->overtimeFor($this->employee, '2026-08-03', $this->logsFor('2026-08-03'));

        $this->assertSame(0, $result['overtime']);
    }
}
