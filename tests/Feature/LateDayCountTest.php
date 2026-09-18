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
 * Lateness is counted in days, not in punches.
 *
 * A day is one row per punch, so somebody who clocks in late, steps out and
 * comes back has one late morning and three `in` rows. Counting the rows put
 * three black marks against one present day, in a column sitting next to a
 * present-days figure that has always counted days — two numbers answering
 * different questions under headings that promise the same one, in a report
 * that reaches payroll.
 *
 * The first punch of the day decides. `status` is computed per punch against
 * the shift start, so an afternoon return is stamped `late` as arithmetic; it
 * is not a late arrival.
 */
class LateDayCountTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Shift $shift;
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

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $this->employee = $this->makeEmployee($department->id, 'E1', 'ann@acme.test');
    }

    private function makeEmployee(int $departmentId, string $code, string $email): Employee
    {
        $user = User::create([
            'name' => $code, 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        return Employee::create([
            'company_id' => $this->company->id, 'department_id' => $departmentId,
            'office_id' => $this->office->id, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $code, 'last_name' => 'Tester',
            'status' => 'active', 'shift_id' => $this->shift->id,
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $punches time => status */
    private function checkIns(Employee $employee, string $date, array $punches): void
    {
        foreach ($punches as [$time, $status]) {
            AttendanceLog::create([
                'company_id'  => $this->company->id,
                'employee_id' => $employee->id,
                'office_id'   => $this->office->id,
                'type'        => 'in',
                'scanned_at'  => Carbon::parse("$date $time"),
                'work_date'   => $date,
                'status'      => $status,
                'source'      => 'mobile',
            ]);
        }
    }

    private function lateDays(?Employee $only = null): int
    {
        // Insertion order, deliberately not clock order. Sorting here would do
        // the job lateDayKeys exists to do and the test would pass without it.
        $logs = AttendanceLog::query()
            ->when($only, fn ($q) => $q->where('employee_id', $only->id))
            ->orderBy('id')
            ->get();

        return app(AttendanceService::class)->lateDayKeys($logs)->count();
    }

    public function test_one_late_morning_with_three_check_ins_is_one_late_day(): void
    {
        // The bug this file exists for: in at 09:34, out for an errand, back
        // twice. Every return is stamped late because it is after the grace
        // window, and the day was late exactly once.
        $this->checkIns($this->employee, '2026-04-06', [
            ['09:34:00', 'late'],
            ['13:10:00', 'late'],
            ['15:40:00', 'late'],
        ]);

        $this->assertSame(1, $this->lateDays());
    }

    public function test_arriving_on_time_and_returning_later_is_not_a_late_day(): void
    {
        // The first punch decides. Coming back from an errand at two o'clock is
        // stamped late by the arithmetic and is not a late arrival.
        $this->checkIns($this->employee, '2026-04-07', [
            ['08:58:00', 'ontime'],
            ['14:02:00', 'late'],
        ]);

        $this->assertSame(0, $this->lateDays());
    }

    public function test_arriving_late_is_a_late_day_however_the_rest_of_it_goes(): void
    {
        $this->checkIns($this->employee, '2026-04-08', [
            ['09:41:00', 'late'],
            ['09:50:00', 'ontime'],
        ]);

        $this->assertSame(1, $this->lateDays());
    }

    public function test_the_first_punch_is_the_earliest_not_the_first_inserted(): void
    {
        // Rows do not arrive in order — an offline punch syncs after the ones
        // made later, and a regularisation inserts one into the middle of a day
        // that is already closed.
        $this->checkIns($this->employee, '2026-04-09', [
            ['14:00:00', 'late'],
            ['08:55:00', 'ontime'],
        ]);

        $this->assertSame(0, $this->lateDays());
    }

    public function test_late_days_never_exceed_present_days(): void
    {
        $this->checkIns($this->employee, '2026-04-13', [
            ['09:30:00', 'late'], ['11:00:00', 'late'], ['14:00:00', 'late'],
        ]);
        $this->checkIns($this->employee, '2026-04-14', [['08:50:00', 'ontime']]);

        $presentDays = AttendanceLog::where('employee_id', $this->employee->id)
            ->where('type', 'in')->get()
            ->pluck('work_date')->map->toDateString()->unique()->count();

        // The reconciliation the report could not previously make: five punches
        // over two days, one of them late.
        $this->assertSame(2, $presentDays);
        $this->assertSame(1, $this->lateDays());
        $this->assertLessThanOrEqual($presentDays, $this->lateDays());
    }

    public function test_one_persons_lateness_is_not_counted_against_another(): void
    {
        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Second', 'shift_id' => $this->shift->id,
        ]);
        $other = $this->makeEmployee($department->id, 'E2', 'bob@acme.test');

        // Same date, same shift, different people: the key carries the employee
        // so a company-wide collection does not collapse them into one day.
        $this->checkIns($this->employee, '2026-04-15', [['09:30:00', 'late']]);
        $this->checkIns($other, '2026-04-15', [['09:45:00', 'late']]);

        $this->assertSame(2, $this->lateDays());
        $this->assertSame(1, $this->lateDays($this->employee));
        $this->assertSame(1, $this->lateDays($other));
    }

    public function test_check_outs_are_never_counted_as_arrivals(): void
    {
        // The check-out is EARLIER than the arrival — the shape a forgotten
        // overnight check-out leaves once a regularisation closes it. Put it
        // after the arrival and it can never be picked as the day's first punch,
        // so the test passes whether or not the type filter is there at all.
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'office_id' => $this->office->id, 'type' => 'out',
            'scanned_at' => Carbon::parse('2026-04-16 06:00:00'),
            'work_date' => '2026-04-16', 'status' => 'late', 'source' => 'mobile',
        ]);

        $this->checkIns($this->employee, '2026-04-16', [['08:55:00', 'ontime']]);

        // An `out` carrying a late status is not an arrival and must not make
        // the day one.
        $this->assertSame(0, $this->lateDays());
    }

    public function test_a_day_with_no_punches_counts_as_nothing(): void
    {
        $this->assertSame(0, $this->lateDays());
    }
}
