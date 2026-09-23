<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HR reading one employee's attendance, day by day, from the phone.
 *
 * **The window is the thing worth testing.** The app sends a `period` and never
 * a date, because a phone is wherever its owner is and attendance is judged in
 * the company's zone — trap 30 in CLAUDE.md is the record of the four times
 * that rule has been paid for. So every test here asserts the dates the server
 * *named*, and the company below is deliberately on `America/New_York` rather
 * than UTC so a window resolved against the wrong clock lands a day out.
 *
 * The second risk is disclosure, and it is the same one `HrEmployeeRegisterTest`
 * exists for: a manager holds `view-team` and sees their own reports' attendance
 * through `/manager/*`, but never the HR surface. A new endpoint is exactly how
 * that quietly stops being true, so the gate is tested from both sides.
 */
class HrAttendanceHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $shift;
    protected User $hr;
    protected Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A fixed, known "now" so the periods resolve to the same dates every
        // run. 2026-08-12 is a Wednesday, so "this week" starts on the 10th.
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
            'company_id' => $this->company->id, 'name' => 'Ops',
            'shift_id' => $this->shift->id,
        ]);

        $this->hr = $this->account('hr@acme.test', 'hr');

        $this->staff = Employee::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'office_id'     => $this->office->id,
            'employee_code' => 'EMP-0001',
            'first_name'    => 'Ann',
            'last_name'     => 'Lee',
            'status'        => 'active',
        ]);
    }

    protected function account(string $email, string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A day of punches.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $punches
     */
    protected function day(string $date, array $punches, ?Employee $for = null): void
    {
        $for ??= $this->staff;

        foreach ($punches as $punch) {
            AttendanceLog::create([
                'company_id'  => $for->company_id,
                'employee_id' => $for->id,
                'office_id'   => $for->office_id,
                'type'        => $punch[0],
                'scanned_at'  => Carbon::parse("$date {$punch[1]}"),
                'work_date'   => $date,
                'status'      => $punch[2] ?? 'ontime',
                'source'      => 'mobile',
            ]);
        }
    }

    protected function url(array $query = []): string
    {
        return '/api/v1/hr/employees/' . $this->staff->id . '/attendance'
            . ($query ? '?' . http_build_query($query) : '');
    }

    // -------------------------------------------------------------------------
    // The window is the server's to name
    // -------------------------------------------------------------------------

    public function test_the_default_period_is_thirty_days_ending_today(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('period', 'daily')
            ->assertJsonPath('to', '2026-08-12')
            ->assertJsonPath('from', '2026-07-14')
            ->assertJsonPath('today', '2026-08-12');
    }

    public function test_weekly_and_monthly_resolve_their_own_windows(): void
    {
        Sanctum::actingAs($this->hr);

        // The 12th is a Wednesday; the week starts on Monday the 10th.
        $this->getJson($this->url(['period' => 'weekly']))
            ->assertOk()
            ->assertJsonPath('from', '2026-08-10')
            ->assertJsonPath('to', '2026-08-12');

        $this->getJson($this->url(['period' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('from', '2026-08-01')
            ->assertJsonPath('to', '2026-08-12');
    }

    public function test_a_custom_range_is_honoured_and_clamped_to_today(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson($this->url(['period' => 'custom', 'from' => '2026-08-03', 'to' => '2026-08-05']))
            ->assertOk()
            ->assertJsonPath('from', '2026-08-03')
            ->assertJsonPath('to', '2026-08-05');

        // A day that has not happened cannot be an absence.
        $this->getJson($this->url(['period' => 'custom', 'from' => '2026-08-10', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertJsonPath('to', '2026-08-12');
    }

    public function test_a_reversed_range_is_refused(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson($this->url(['period' => 'custom', 'from' => '2026-08-10', 'to' => '2026-08-01']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_range');
    }

    public function test_a_window_longer_than_the_ceiling_is_refused(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson($this->url(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-08-12']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'range_too_large');
    }

    public function test_a_nonsense_period_is_a_validation_failure(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson($this->url(['period' => 'yearly']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    // -------------------------------------------------------------------------
    // What a day says
    // -------------------------------------------------------------------------

    public function test_a_day_carries_its_check_in_break_and_check_out(): void
    {
        // In at 09:00, an hour's lunch, out at 18:00 — nine hours present, one
        // on lunch, eight paid.
        $this->day('2026-08-03', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['break_end', '14:00:00'],
            ['out', '18:00:00'],
        ]);

        Sanctum::actingAs($this->hr);

        $res = $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-03', 'to' => '2026-08-03',
        ]))->assertOk();

        $res->assertJsonPath('days.0.date', '2026-08-03')
            ->assertJsonPath('days.0.status', 'present')
            ->assertJsonPath('days.0.break_minutes', 60)
            ->assertJsonPath('days.0.worked_minutes', 480)
            ->assertJsonPath('days.0.punches', 4)
            ->assertJsonPath('days.0.shift', 'Morning Shift');

        // Times are the company's wall clock, not the server's zone.
        $this->assertStringContainsString('T09:00:00', $res->json('days.0.first_in'));
        $this->assertStringContainsString('T13:00:00', $res->json('days.0.break_start'));
        $this->assertStringContainsString('T14:00:00', $res->json('days.0.break_end'));
        $this->assertStringContainsString('T18:00:00', $res->json('days.0.last_out'));
    }

    public function test_two_breaks_in_a_day_sum_and_an_open_one_costs_nothing(): void
    {
        $this->day('2026-08-04', [
            ['in', '09:00:00'],
            ['break_start', '11:00:00'], ['break_end', '11:15:00'],
            ['break_start', '13:00:00'], ['break_end', '13:45:00'],
            ['out', '17:00:00'],
        ]);

        // Its length is unknown. Running it to the check-out would guess it long
        // and quietly cut the day.
        $this->day('2026-08-05', [
            ['in', '09:00:00'],
            ['break_start', '13:00:00'],
            ['out', '17:00:00'],
        ]);

        Sanctum::actingAs($this->hr);

        $res = $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-04', 'to' => '2026-08-05',
        ]))->assertOk();

        // Newest first.
        $res->assertJsonPath('days.0.date', '2026-08-05')
            ->assertJsonPath('days.0.break_count', 1)
            ->assertJsonPath('days.0.break_minutes', 0)
            ->assertJsonPath('days.0.worked_minutes', 480)
            ->assertJsonPath('days.0.break_end', null)
            ->assertJsonPath('days.1.date', '2026-08-04')
            ->assertJsonPath('days.1.break_count', 2)
            ->assertJsonPath('days.1.break_minutes', 60)
            ->assertJsonPath('days.1.worked_minutes', 420);
    }

    /**
     * The count is what stops the envelope reading as a single long break.
     *
     * Three breaks, the last never ended: `break_start` is 11:00 and
     * `break_end` is 13:45 because they are the first and the last, and the
     * 2h45m between them is not a break — 60 minutes of it is. Without
     * `break_count` there is nothing in the row that says so, and the open
     * break at 16:30 does not appear at all, because `break_end` is not null.
     */
    public function test_the_break_count_distinguishes_an_envelope_from_a_break(): void
    {
        $this->day('2026-08-04', [
            ['in', '09:00:00'],
            ['break_start', '11:00:00'], ['break_end', '11:15:00'],
            ['break_start', '13:00:00'], ['break_end', '13:45:00'],
            ['break_start', '16:30:00'],
            ['out', '17:00:00'],
        ]);

        Sanctum::actingAs($this->hr);

        $res = $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-04', 'to' => '2026-08-04',
        ]))->assertOk();

        // Started three, charged for two — the open one has no known length.
        $res->assertJsonPath('days.0.break_count', 3)
            ->assertJsonPath('days.0.break_minutes', 60)
            ->assertJsonPath('days.0.worked_minutes', 420)
            ->assertJsonPath('days.0.punches', 7);

        $this->assertStringContainsString('T11:00:00', $res->json('days.0.break_start'));
        $this->assertStringContainsString('T13:45:00', $res->json('days.0.break_end'));
    }

    public function test_a_day_with_no_break_reports_a_count_of_zero(): void
    {
        $this->day('2026-08-04', [['in', '09:00:00'], ['out', '17:00:00']]);

        Sanctum::actingAs($this->hr);

        $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-04', 'to' => '2026-08-04',
        ]))->assertOk()
            ->assertJsonPath('days.0.break_count', 0)
            ->assertJsonPath('days.0.break_start', null)
            ->assertJsonPath('days.0.break_end', null);
    }

    public function test_late_and_early_are_flagged_and_totalled(): void
    {
        $this->day('2026-08-06', [
            ['in', '09:45:00', 'late'],
            ['out', '15:00:00', 'early_leave'],
        ]);

        Sanctum::actingAs($this->hr);

        $this->getJson($this->url(['period' => 'custom', 'from' => '2026-08-06', 'to' => '2026-08-06']))
            ->assertOk()
            ->assertJsonPath('days.0.late', true)
            ->assertJsonPath('days.0.early_leave', true)
            ->assertJsonPath('totals.late_days', 1)
            ->assertJsonPath('totals.early_leave_days', 1);
    }

    public function test_a_weekend_is_not_an_absence(): void
    {
        Sanctum::actingAs($this->hr);

        // 8 and 9 August 2026 are a Saturday and a Sunday.
        $res = $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-08', 'to' => '2026-08-10',
        ]))->assertOk();

        $this->assertSame('absent', $res->json('days.0.status'));   // Mon 10th
        $this->assertSame('weekend', $res->json('days.1.status'));  // Sun 9th
        $this->assertSame('weekend', $res->json('days.2.status'));  // Sat 8th
        $res->assertJsonPath('totals.absent_days', 1);
    }

    // -------------------------------------------------------------------------
    // Who may reach it
    // -------------------------------------------------------------------------

    public function test_a_manager_is_refused(): void
    {
        $manager = $this->account('lead@acme.test', 'manager');

        // The whole point: a manager may read their own team's attendance
        // through /manager/*, and must never reach the HR surface.
        $this->assertFalse($manager->can('manage-employees'));

        Sanctum::actingAs($manager);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_an_ordinary_employee_is_refused(): void
    {
        Sanctum::actingAs($this->account('ann@acme.test', 'employee'));
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_another_companys_employee_is_refused(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirOffice = Office::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirDept = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'department_id' => $theirDept->id,
            'office_id' => $theirOffice->id, 'employee_code' => 'X-1',
            'first_name' => 'Someone', 'last_name' => 'Else', 'status' => 'active',
        ]);

        // Route-model binding resolves by id alone and the permission is
        // company-blind, so the controller is the only thing in the way.
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/hr/employees/' . $theirs->id . '/attendance')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // The register's own summary line
    // -------------------------------------------------------------------------

    public function test_the_register_carries_a_summary_that_matches_the_days(): void
    {
        $this->day('2026-08-03', [
            ['in', '09:00:00'], ['break_start', '13:00:00'],
            ['break_end', '14:00:00'], ['out', '18:00:00'],
        ]);
        $this->day('2026-08-04', [['in', '09:45:00', 'late'], ['out', '17:00:00']]);

        Sanctum::actingAs($this->hr);

        $register = $this->getJson('/api/v1/hr/employees?' . http_build_query([
            'period' => 'custom', 'from' => '2026-08-03', 'to' => '2026-08-04',
        ]))->assertOk();

        $register->assertJsonPath('from', '2026-08-03')
            ->assertJsonPath('today', '2026-08-12')
            ->assertJsonPath('people.0.attendance.present_days', 2)
            ->assertJsonPath('people.0.attendance.late_days', 1)
            ->assertJsonPath('people.0.attendance.break_minutes', 60);

        // And it agrees with the day rows for the same window.
        $days = $this->getJson($this->url([
            'period' => 'custom', 'from' => '2026-08-03', 'to' => '2026-08-04',
        ]))->assertOk();

        // Every field, not just the breaks. An earlier version of this test
        // compared break_minutes alone and passed while worked_minutes was an
        // hour a day out: the register was reporting payroll's settled figure,
        // which charges the shift's nominal break on a day nobody punched one,
        // under the same label the day rows use for what was actually punched.
        foreach ([
            'present_days', 'late_days', 'early_leave_days',
            'worked_minutes', 'break_minutes',
        ] as $key) {
            $this->assertSame(
                $days->json("totals.$key"),
                $register->json("people.0.attendance.$key"),
                "register and day rows disagree on $key",
            );
        }
    }

    /**
     * The register must not quietly switch to payroll's break policy.
     *
     * 04 Aug runs 09:45 to 17:00 with no break punched at all. Payroll charges
     * the shift's 60m nominal break for it; an attendance record shows the 435
     * minutes the badge recorded. This is the exact day that made the two
     * screens disagree, so it is asserted on its own rather than only through
     * the totals above.
     */
    public function test_a_day_with_no_punched_break_is_not_charged_a_nominal_one(): void
    {
        $this->day('2026-08-04', [['in', '09:45:00', 'late'], ['out', '17:00:00']]);

        Sanctum::actingAs($this->hr);

        $window = ['period' => 'custom', 'from' => '2026-08-04', 'to' => '2026-08-04'];

        $this->getJson($this->url($window))
            ->assertOk()
            ->assertJsonPath('days.0.break_minutes', 0)
            ->assertJsonPath('days.0.worked_minutes', 435);

        $this->getJson('/api/v1/hr/employees?' . http_build_query($window))
            ->assertOk()
            ->assertJsonPath('people.0.attendance.break_minutes', 0)
            ->assertJsonPath('people.0.attendance.worked_minutes', 435);
    }

    public function test_a_person_with_no_punches_gets_zeros_not_nulls(): void
    {
        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/hr/employees')
            ->assertOk()
            ->assertJsonPath('people.0.attendance.present_days', 0)
            ->assertJsonPath('people.0.attendance.worked_minutes', 0);
    }
}
