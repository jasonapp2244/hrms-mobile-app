<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The personal attendance score and the on-time streak (B3.5).
 *
 * Two numbers with deliberately different shapes. The score answers for the
 * window on screen and is read off the very rows printed under it; the streak
 * ignores the window entirely, because "eleven days" has to mean eleven days
 * and not "eleven of the last thirty".
 *
 * Most of what follows is about the days that should count for neither — a
 * holiday, a booked week off, a rostered day off — and about the morning case,
 * which is the one that decides whether the number is usable at all.
 */
class AttendanceScoreApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Shift $shift;
    protected User $user;
    protected Employee $employee;
    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ']);

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $this->leaveType = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'days_per_year' => 20,
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
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** An arrival on a date. `$late` marks it the way a real punch would be. */
    protected function arrived(string $date, bool $late = false, string $at = '08:55:00'): AttendanceLog
    {
        return AttendanceLog::create([
            'company_id'  => $this->company->id,
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'in',
            'scanned_at'  => Carbon::parse("{$date} {$at}"),
            'work_date'   => $date,
            'status'      => $late ? 'late' : 'ontime',
            'source'      => 'mobile',
        ]);
    }

    protected function bookedOff(string $from, string $to): void
    {
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $from, 'end_date' => $to, 'days' => 1, 'status' => 'approved',
        ]);
    }

    /** The score block for a window ending on the travelled-to day. */
    protected function score(string $from, string $to): array
    {
        return $this->getJson("/api/v1/attendance/history?from={$from}&to={$to}")
            ->assertOk()
            ->json('score');
    }

    protected function streak(): int
    {
        return app(AttendanceService::class)->onTimeStreak($this->employee->fresh());
    }

    // ================= the score =================

    public function test_a_perfect_week_scores_a_hundred(): void
    {
        // Mon 3 to Fri 7 August 2026.
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        foreach (['03', '04', '05', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $score = $this->score('2026-08-03', '2026-08-07');

        $this->assertSame(100, $score['score']);
        $this->assertSame(5, $score['ontime_days']);
        $this->assertSame(5, $score['obliged_days']);
    }

    public function test_a_late_day_costs_the_same_as_an_absent_one(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        // Four of five on time: one late, and the same shape again with one
        // missed entirely, must land on the same number. Being here and being
        // on time are the two things the score measures, and it measures them
        // together.
        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-05');
        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07', late: true);

        $this->assertSame(80, $this->score('2026-08-03', '2026-08-07')['score']);
    }

    public function test_an_absence_costs_the_same(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        foreach (['03', '04', '05', '06'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $this->assertSame(80, $this->score('2026-08-03', '2026-08-07')['score']);
    }

    public function test_the_weekend_is_not_in_the_denominator(): void
    {
        // Mon 3 to Sun 9 August: seven calendar days, five working ones.
        $this->travelTo(Carbon::parse('2026-08-09 18:00:00'));

        foreach (['03', '04', '05', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $score = $this->score('2026-08-03', '2026-08-09');

        $this->assertSame(5, $score['obliged_days']);
        $this->assertSame(100, $score['score']);
    }

    public function test_a_company_holiday_is_not_in_the_denominator(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Summer', 'date' => '2026-08-05',
        ]);

        foreach (['03', '04', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $score = $this->score('2026-08-03', '2026-08-07');

        $this->assertSame(4, $score['obliged_days']);
        $this->assertSame(100, $score['score']);
    }

    public function test_approved_leave_neither_helps_nor_hurts(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->bookedOff('2026-08-05', '2026-08-05');

        foreach (['03', '04', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $score = $this->score('2026-08-03', '2026-08-07');

        $this->assertSame(4, $score['obliged_days']);
        $this->assertSame(100, $score['score']);
    }

    public function test_a_rostered_day_off_is_not_a_day_they_were_expected(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-05', 'is_day_off' => true,
        ]);

        foreach (['03', '04', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $this->assertSame(4, $this->score('2026-08-03', '2026-08-07')['obliged_days']);
    }

    public function test_a_window_nobody_was_expected_in_has_no_score_rather_than_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-08-09 18:00:00'));

        // A weekend. Zero would read as a failure, and it is the worst thing
        // to show somebody looking at a quiet fortnight.
        $score = $this->score('2026-08-08', '2026-08-09');

        $this->assertNull($score['score']);
        $this->assertSame(0, $score['obliged_days']);
    }

    public function test_a_whole_window_on_leave_has_no_score(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->bookedOff('2026-08-03', '2026-08-07');

        $this->assertNull($this->score('2026-08-03', '2026-08-07')['score']);
    }

    public function test_the_score_agrees_with_the_rows_printed_under_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04', late: true);
        $this->arrived('2026-08-06');

        $body = $this->getJson('/api/v1/attendance/history?from=2026-08-03&to=2026-08-07')
            ->assertOk()->json();

        $days = collect($body['days']);

        // The whole reason it is computed from the rows: a number that
        // disagreed with the list it sits on would be worse than no number.
        $this->assertSame(
            $days->whereIn('status', ['present', 'absent'])->count(),
            $body['score']['obliged_days'],
        );
        $this->assertSame(
            $days->where('status', 'present')->where('late', false)->count(),
            $body['score']['ontime_days'],
        );
    }

    // ================= the streak =================

    public function test_consecutive_on_time_days_count(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        foreach (['03', '04', '05', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        $this->assertSame(5, $this->streak());
    }

    public function test_a_late_arrival_ends_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-05', late: true);
        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');

        // The two since, not the five in total.
        $this->assertSame(2, $this->streak());
    }

    public function test_an_absence_ends_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        // Nothing on the 5th.
        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');

        $this->assertSame(2, $this->streak());
    }

    public function test_a_weekend_does_not_break_it(): void
    {
        // Fri 7 and the following Mon 10, either side of a weekend.
        $this->travelTo(Carbon::parse('2026-08-10 18:00:00'));

        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');
        $this->arrived('2026-08-10');

        $this->assertSame(3, $this->streak());
    }

    public function test_a_week_of_booked_leave_does_not_cost_somebody_their_record(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 18:00:00'));

        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');
        $this->bookedOff('2026-08-10', '2026-08-14');
        $this->arrived('2026-08-17');

        $this->assertSame(3, $this->streak());
    }

    public function test_a_rostered_day_off_does_not_break_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-05', 'is_day_off' => true,
        ]);

        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');

        $this->assertSame(4, $this->streak());
    }

    public function test_a_holiday_does_not_break_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Summer', 'date' => '2026-08-05',
        ]);

        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-06');
        $this->arrived('2026-08-07');

        $this->assertSame(4, $this->streak());
    }

    public function test_this_morning_before_anybody_has_clocked_in_still_shows_yesterdays_streak(): void
    {
        // Half past eight on a working Thursday. Nobody has arrived yet.
        // Counting today as an absence here would show every employee in the
        // company a zero every morning, which is the whole feature wasted.
        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-05');

        $this->travelTo(Carbon::parse('2026-08-06 08:30:00'));

        $this->assertSame(3, $this->streak());
    }

    public function test_clocking_in_this_morning_adds_to_it_immediately(): void
    {
        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');
        $this->arrived('2026-08-05');

        $this->travelTo(Carbon::parse('2026-08-06 08:55:00'));
        $this->arrived('2026-08-06');

        $this->assertSame(4, $this->streak());
    }

    public function test_arriving_late_this_morning_ends_it_at_once(): void
    {
        $this->arrived('2026-08-03');
        $this->arrived('2026-08-04');

        $this->travelTo(Carbon::parse('2026-08-05 09:30:00'));
        $this->arrived('2026-08-05', late: true, at: '09:30:00');

        // Today is never held against somebody for *not* having arrived. Having
        // arrived late is a different matter — that is a fact, not an
        // unfinished day.
        $this->assertSame(0, $this->streak());
    }

    public function test_somebody_who_has_never_clocked_in_has_no_streak(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        $this->assertSame(0, $this->streak());
    }

    public function test_the_streak_ignores_the_window_on_screen(): void
    {
        $this->travelTo(Carbon::parse('2026-08-07 18:00:00'));

        foreach (['03', '04', '05', '06', '07'] as $d) {
            $this->arrived("2026-08-{$d}");
        }

        // A one-day window. The score narrows to it; the streak does not,
        // because "five days" has to mean five days.
        $body = $this->getJson('/api/v1/attendance/history?from=2026-08-07&to=2026-08-07')
            ->assertOk()->json();

        $this->assertSame(1, $body['score']['obliged_days']);
        $this->assertSame(5, $body['score']['streak']);
    }

    // ================= the stored monthly row =================

    public function test_the_monthly_row_scores_the_same_way_the_api_does(): void
    {
        $this->travelTo(Carbon::parse('2026-08-31 23:00:00'));

        // Every working day in August 2026, the first of them late.
        $service = app(AttendanceService::class);

        $working = app(\App\Services\LeaveService::class)
            ->workingDatesBetween($this->company, '2026-08-01', '2026-08-31');

        foreach ($working as $i => $date) {
            $this->arrived($date, late: $i === 0);
        }

        $service->computeMonthlyScore($this->employee, '2026-08');

        $row = \App\Models\AttendanceScore::first();
        $expected = $service->scorePercent(count($working) - 1, count($working));

        // The column used to be a copy of ontime_pct under a different name.
        // Now it means what the app shows, so the two cannot drift.
        $this->assertSame((float) $expected, (float) $row->score);
    }
}
