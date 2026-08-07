<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
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
 * A4.19 the live board and A6.7 the leave calendar.
 *
 * The board's contract is that its four buckets partition the roster: everybody
 * appears exactly once. A board that double-counts, or that quietly drops
 * somebody, is one people stop trusting after the first time they notice.
 */
class LiveBoardAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');

        $this->staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->staff->assignRole('employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $first): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . $n, 'first_name' => $first, 'last_name' => 'Test',
            'status' => 'active', 'work_mode' => 'office',
        ]);
    }

    private function punch(Employee $e, string $type, string $time): void
    {
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $e->id,
            'office_id' => $this->office->id, 'type' => $type,
            'scanned_at' => Carbon::parse($time), 'work_date' => Carbon::parse($time)->toDateString(),
            'status' => 'ontime', 'source' => 'button',
        ]);
    }

    private function board(): array
    {
        return app(AttendanceService::class)->whoIsIn($this->company->id);
    }

    // -------------------------------------------------------------------------
    // A4.19 — the live board
    // -------------------------------------------------------------------------

    public function test_somebody_clocked_in_is_on_the_clock(): void
    {
        Carbon::setTestNow('2026-08-05 11:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, 'in', '2026-08-05 09:00:00');

        $board = $this->board();

        $this->assertCount(1, $board['in']);
        $this->assertSame($ann->id, $board['in'][0]['employee']->id);
        $this->assertFalse($board['in'][0]['on_break']);
    }

    public function test_somebody_on_a_break_is_still_on_the_clock_but_flagged(): void
    {
        // They are at work. Moving them to "not accounted for" for twenty
        // minutes would be actively misleading.
        Carbon::setTestNow('2026-08-05 13:10:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, 'in', '2026-08-05 09:00:00');
        $this->punch($ann, 'break_start', '2026-08-05 13:00:00');

        $board = $this->board();

        $this->assertCount(1, $board['in']);
        $this->assertTrue($board['in'][0]['on_break']);
    }

    public function test_somebody_back_from_a_break_is_not_flagged(): void
    {
        Carbon::setTestNow('2026-08-05 14:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, 'in', '2026-08-05 09:00:00');
        $this->punch($ann, 'break_start', '2026-08-05 13:00:00');
        $this->punch($ann, 'break_end', '2026-08-05 13:30:00');

        $this->assertFalse($this->board()['in'][0]['on_break']);
    }

    public function test_somebody_who_clocked_out_has_been_in_and_left(): void
    {
        Carbon::setTestNow('2026-08-05 18:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, 'in', '2026-08-05 09:00:00');
        $this->punch($ann, 'out', '2026-08-05 17:00:00');

        $board = $this->board();

        $this->assertCount(0, $board['in']);
        $this->assertCount(1, $board['left']);
    }

    public function test_somebody_who_left_and_came_back_is_on_the_clock_again(): void
    {
        Carbon::setTestNow('2026-08-05 19:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, 'in', '2026-08-05 09:00:00');
        $this->punch($ann, 'out', '2026-08-05 13:00:00');
        $this->punch($ann, 'in', '2026-08-05 18:00:00');

        $this->assertCount(1, $this->board()['in']);
        $this->assertCount(0, $this->board()['left']);
    }

    public function test_somebody_with_no_punch_is_not_accounted_for(): void
    {
        Carbon::setTestNow('2026-08-05 11:00:00');
        $this->employee('Ghost');

        $this->assertCount(1, $this->board()['not_in']);
    }

    public function test_somebody_on_approved_leave_is_accounted_for(): void
    {
        // The board is read to find who is unaccounted for; booked holiday is
        // accounted for, and putting them in the warning column defeats it.
        Carbon::setTestNow('2026-08-05 11:00:00');

        $ann = $this->employee('Ann');
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'is_active' => true,
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-07', 'days' => 5, 'status' => 'approved',
        ]);

        $board = $this->board();

        $this->assertCount(0, $board['not_in']);
        $this->assertCount(1, $board['on_leave']);
    }

    public function test_the_buckets_partition_the_roster(): void
    {
        Carbon::setTestNow('2026-08-05 18:00:00');

        $in = $this->employee('Working');
        $this->punch($in, 'in', '2026-08-05 09:00:00');

        $out = $this->employee('Finished');
        $this->punch($out, 'in', '2026-08-05 09:00:00');
        $this->punch($out, 'out', '2026-08-05 17:00:00');

        $this->employee('Missing');

        $board = $this->board();
        $counted = count($board['in']) + count($board['left'])
            + count($board['not_in']) + count($board['on_leave']);

        // Everybody exactly once — no double-counting, nobody dropped.
        $this->assertSame(3, $counted);
    }

    public function test_a_night_shift_started_yesterday_still_shows_as_in(): void
    {
        // At two in the morning the person is very much at work, and their punch
        // is on yesterday's work date.
        Carbon::setTestNow('2026-08-06 02:00:00');

        $ann = $this->employee('Night');
        $this->punch($ann, 'in', '2026-08-05 22:00:00');

        $this->assertCount(1, $this->board()['in']);
    }

    public function test_inactive_staff_are_not_on_the_board(): void
    {
        Carbon::setTestNow('2026-08-05 11:00:00');
        $this->employee('Gone')->update(['status' => 'inactive']);

        $board = $this->board();

        $this->assertSame(0, count($board['in']) + count($board['left'])
            + count($board['not_in']) + count($board['on_leave']));
    }

    public function test_hr_can_open_the_board_and_an_employee_cannot(): void
    {
        Carbon::setTestNow('2026-08-05 11:00:00');
        $this->employee('Ann');

        $this->actingAs($this->hr)->get(route('attendance.board'))
            ->assertOk()->assertSee('Who Is In');

        // view-attendance is the employee's own-history permission, so the
        // portal role reaches this route's gate but not the admin layout.
        $this->actingAs($this->staff)->get(route('attendance.board'))->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // A6.7 — the leave calendar
    // -------------------------------------------------------------------------

    public function test_the_calendar_shows_approved_and_pending_leave(): void
    {
        $ann = $this->employee('Ann');
        $bob = $this->employee('Bob');

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'is_active' => true,
        ]);

        foreach ([[$ann, 'approved'], [$bob, 'pending']] as [$employee, $status]) {
            LeaveRequest::create([
                'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'leave_type_id' => $type->id, 'start_date' => '2026-08-10',
                'end_date' => '2026-08-12', 'days' => 3, 'status' => $status,
            ]);
        }

        $this->actingAs($this->hr)
            ->get(route('leave.calendar', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('August 2026')
            // Pending is drawn too, so cover is not approved twice onto one day.
            ->assertSee('2 request(s)');
    }

    public function test_leave_in_another_month_is_not_drawn(): void
    {
        $ann = $this->employee('Ann');
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'is_active' => true,
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-03-02',
            'end_date' => '2026-03-04', 'days' => 3, 'status' => 'approved',
        ]);

        $this->actingAs($this->hr)
            ->get(route('leave.calendar', ['month' => '2026-08']))
            ->assertOk()->assertSee('0 request(s)');
    }

    public function test_leave_running_through_a_month_is_drawn_in_it(): void
    {
        // A long absence has to show up in a month it merely passes through.
        $ann = $this->employee('Ann');
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sabbatical', 'code' => 'SAB',
            'days_per_year' => 90, 'is_paid' => false, 'is_active' => true,
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-07-01',
            'end_date' => '2026-09-30', 'days' => 60, 'status' => 'approved',
        ]);

        $this->actingAs($this->hr)
            ->get(route('leave.calendar', ['month' => '2026-08']))
            ->assertOk()->assertSee('1 request(s)');
    }

    public function test_an_employee_cannot_open_the_calendar(): void
    {
        $this->actingAs($this->staff)->get(route('leave.calendar'))->assertForbidden();
    }
}
