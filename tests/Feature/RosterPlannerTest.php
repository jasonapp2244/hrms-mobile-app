<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\RosterService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The roster planner: planning a day, generating a rotation, and publishing.
 *
 * A planned day beats the standing shift; where nothing is planned the standing
 * shift still applies, so a company that never opens the planner is unaffected.
 */
class RosterPlannerTest extends TestCase
{
    use RefreshDatabase;

    /** Monday. */
    protected const WEEK = '2026-08-03';

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $day;
    protected Shift $night;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse(self::WEEK . ' 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ', 'code' => 'HQ']);

        $this->day = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day', 'code' => 'D',
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'late_grace_minutes' => 15,
        ]);
        $this->night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night', 'code' => 'N',
            'start_time' => '22:00:00', 'end_time' => '06:00:00', 'late_grace_minutes' => 15,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $this->department->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'status' => 'active',
        ]);
    }

    protected function staff(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function roster(): RosterService
    {
        return app(RosterService::class);
    }

    // ================= resolution =================

    public function test_with_nothing_planned_the_standing_shift_applies(): void
    {
        $this->assertSame($this->day->id, $this->employee->shiftOn(self::WEEK)->id);
    }

    public function test_a_planned_day_beats_the_standing_shift(): void
    {
        $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);

        $this->assertSame($this->night->id, $this->employee->fresh()->shiftOn(self::WEEK)->id);
    }

    public function test_a_planned_day_only_affects_that_day(): void
    {
        $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);

        $this->assertSame($this->day->id, $this->employee->fresh()->shiftOn('2026-08-04')->id);
    }

    public function test_a_rostered_day_off_resolves_to_no_shift(): void
    {
        $this->roster()->setDay($this->employee, self::WEEK, 'off');

        $this->assertNull($this->employee->fresh()->shiftOn(self::WEEK));
        $this->assertTrue($this->employee->fresh()->isRosteredOff(self::WEEK));
    }

    public function test_clearing_a_plan_removes_the_row_rather_than_nulling_it(): void
    {
        $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);
        $this->roster()->setDay($this->employee, self::WEEK, null);

        // "Nothing planned" and "planned as nothing" must stay distinguishable.
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame($this->day->id, $this->employee->fresh()->shiftOn(self::WEEK)->id);
    }

    public function test_planning_the_same_day_twice_replaces_rather_than_stacks(): void
    {
        $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);
        $this->roster()->setDay($this->employee, self::WEEK, $this->day->id);

        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame($this->day->id, ShiftAssignment::first()->shift_id);
    }

    public function test_a_shift_from_another_company_cannot_be_planned(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $foreign = Shift::create([
            'company_id' => $other->id, 'name' => 'X', 'start_time' => '08:00:00', 'end_time' => '16:00:00',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->setDay($this->employee, self::WEEK, $foreign->id);
    }

    // ================= attendance follows the plan =================

    public function test_the_planned_shift_is_what_judges_the_punch(): void
    {
        // 22:00 is wildly late for the standing day shift and exactly on time
        // for the night shift planned on that date.
        $this->roster()->setDay($this->employee, '2026-08-04', $this->night->id);

        $this->travelTo(Carbon::parse('2026-08-04 22:00:00'));
        $log = app(AttendanceService::class)
            ->record($this->employee->fresh(), $this->office, ['source' => 'button'])['log'];

        $this->assertSame('ontime', $log->status);
    }

    public function test_a_night_shift_planned_yesterday_still_owns_this_mornings_punch(): void
    {
        // Planned Night on the 4th, Day on the 5th. The 06:00 clock-out on the
        // 5th belongs to the 4th — which is yesterday's plan, not today's.
        $this->roster()->setDay($this->employee, '2026-08-04', $this->night->id);
        $this->roster()->setDay($this->employee, '2026-08-05', $this->day->id);

        $this->travelTo(Carbon::parse('2026-08-04 22:00:00'));
        app(AttendanceService::class)->record($this->employee->fresh(), $this->office, ['source' => 'button']);

        $this->travelTo(Carbon::parse('2026-08-05 06:00:00'));
        $out = app(AttendanceService::class)
            ->record($this->employee->fresh(), $this->office, ['source' => 'button'])['log'];

        $this->assertSame('2026-08-04', $out->work_date->toDateString());
        $this->assertSame('out', $out->type);
        $this->assertSame('ontime', $out->status);
    }

    // ================= rotation =================

    public function test_a_seven_day_pattern_repeats_on_the_same_weekdays(): void
    {
        $cycle = [
            $this->day->id, $this->day->id, $this->night->id,
            $this->night->id, 'off', 'off', 'off',
        ];

        $this->roster()->generateRotation([$this->employee], $cycle, self::WEEK, 2);

        $employee = $this->employee->fresh();
        // Monday is Day in both weeks.
        $this->assertSame($this->day->id, $employee->shiftOn('2026-08-03')->id);
        $this->assertSame($this->day->id, $employee->shiftOn('2026-08-10')->id);
        // Wednesday is Night in both.
        $this->assertSame($this->night->id, $employee->shiftOn('2026-08-05')->id);
        $this->assertSame($this->night->id, $employee->shiftOn('2026-08-12')->id);
    }

    public function test_a_four_day_pattern_walks_around_the_week(): void
    {
        // Two on, two off — the point of a rotation is that it does not line up
        // with the week, so the same weekday differs from one week to the next.
        $cycle = [$this->day->id, $this->day->id, 'off', 'off'];

        $this->roster()->generateRotation([$this->employee], $cycle, self::WEEK, 2);

        $employee = $this->employee->fresh();
        $this->assertSame($this->day->id, $employee->shiftOn('2026-08-03')->id);   // Mon: on
        $this->assertNull($employee->shiftOn('2026-08-05'));                        // Wed: off
        $this->assertSame($this->day->id, $employee->shiftOn('2026-08-07')->id);   // Fri: on
        // Next Monday lands on the off half of the cycle.
        $this->assertNull($employee->shiftOn('2026-08-10'));
    }

    public function test_a_rotation_covers_every_day_of_every_week_requested(): void
    {
        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 3);

        $this->assertSame(21, ShiftAssignment::count());
    }

    public function test_generating_replaces_an_existing_plan_in_the_range(): void
    {
        $this->roster()->setDay($this->employee, '2026-08-05', $this->night->id);

        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 1);

        // Layering the pattern over the old plan would produce a roster nobody
        // asked for, so the range is cleared first.
        $this->assertSame(7, ShiftAssignment::count());
        $this->assertSame($this->day->id, $this->employee->fresh()->shiftOn('2026-08-05')->id);
    }

    public function test_an_empty_pattern_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->generateRotation([$this->employee], ['', null], self::WEEK, 1);
    }

    public function test_a_pattern_longer_than_the_maximum_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->generateRotation(
            [$this->employee], array_fill(0, 8, $this->day->id), self::WEEK, 1,
        );
    }

    // ================= publishing =================

    public function test_a_planned_day_starts_as_a_draft(): void
    {
        $assignment = $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);

        $this->assertFalse($assignment->isPublished());
    }

    public function test_publishing_a_week_makes_it_visible(): void
    {
        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 1);

        $count = $this->roster()->publish($this->company->id, self::WEEK, '2026-08-09');

        $this->assertSame(7, $count);
        $this->assertSame(7, ShiftAssignment::published()->count());
    }

    public function test_publishing_does_not_reach_outside_the_week(): void
    {
        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 2);

        $this->roster()->publish($this->company->id, self::WEEK, '2026-08-09');

        $this->assertSame(7, ShiftAssignment::published()->count());
        $this->assertSame(7, ShiftAssignment::whereNull('published_at')->count());
    }

    public function test_a_week_can_be_withdrawn_again(): void
    {
        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 1);
        $this->roster()->publish($this->company->id, self::WEEK, '2026-08-09');

        $this->roster()->unpublish($this->company->id, self::WEEK, '2026-08-09');

        $this->assertSame(0, ShiftAssignment::published()->count());
    }

    public function test_staff_only_see_published_days(): void
    {
        $user = User::create([
            'name' => 'Ann', 'email' => 'ann@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');
        $this->employee->update(['user_id' => $user->id]);

        $this->roster()->setDay($this->employee, self::WEEK, $this->night->id);

        // Draft: nothing on the portal.
        $this->actingAs($user)->get('/employee/dashboard')
            ->assertOk()
            ->assertDontSee('My Upcoming Schedule');

        $this->roster()->publish($this->company->id, self::WEEK, '2026-08-09');

        $this->actingAs($user)->get('/employee/dashboard')
            ->assertOk()
            ->assertSee('My Upcoming Schedule');
    }

    // ================= the screen =================

    public function test_the_planner_saves_a_week_from_the_form(): void
    {
        $this->actingAs($this->staff('hr'))
            ->post('/shifts/roster', [
                'week'   => self::WEEK,
                'roster' => [
                    $this->employee->id => [
                        '2026-08-03' => $this->night->id,
                        '2026-08-04' => 'off',
                        '2026-08-05' => '',
                    ],
                ],
            ])
            ->assertRedirect();

        $employee = $this->employee->fresh();
        $this->assertSame($this->night->id, $employee->shiftOn('2026-08-03')->id);
        $this->assertTrue($employee->isRosteredOff('2026-08-04'));
        // The blank cleared the day rather than planning a blank one.
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_the_planner_ignores_an_employee_outside_the_company(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $outsider = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'Z1', 'first_name' => 'Zed',
        ]);

        $this->actingAs($this->staff('hr'))
            ->post('/shifts/roster', [
                'week'   => self::WEEK,
                'roster' => [$outsider->id => ['2026-08-03' => $this->night->id]],
            ])
            ->assertRedirect();

        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_the_rotation_form_generates_and_redirects(): void
    {
        $this->actingAs($this->staff('hr'))
            ->post('/shifts/roster/rotation', [
                'week'         => self::WEEK,
                'start_date'   => self::WEEK,
                'weeks'        => 2,
                'employee_ids' => [$this->employee->id],
                'cycle'        => [$this->day->id, 'off'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(14, ShiftAssignment::count());
    }

    public function test_the_rotation_form_requires_at_least_one_employee(): void
    {
        $this->actingAs($this->staff('hr'))
            ->post('/shifts/roster/rotation', [
                'week'       => self::WEEK,
                'start_date' => self::WEEK,
                'weeks'      => 1,
                'cycle'      => [$this->day->id],
            ])
            ->assertSessionHasErrors('employee_ids');
    }

    public function test_the_publish_button_publishes_the_displayed_week(): void
    {
        $this->roster()->generateRotation([$this->employee], [$this->day->id], self::WEEK, 1);

        $this->actingAs($this->staff('hr'))
            ->post('/shifts/roster/publish', ['week' => self::WEEK, 'action' => 'publish'])
            ->assertRedirect();

        $this->assertSame(7, ShiftAssignment::published()->count());
    }

    public function test_an_employee_cannot_reach_the_planner(): void
    {
        $this->actingAs($this->staff('employee'))
            ->post('/shifts/roster', ['week' => self::WEEK, 'roster' => []])
            ->assertForbidden();
    }

    public function test_the_roster_screen_shows_the_plan(): void
    {
        $this->roster()->setDay($this->employee, '2026-08-04', $this->night->id);

        $response = $this->actingAs($this->staff('hr'))
            ->get('/shifts/roster?week=' . self::WEEK)
            ->assertOk();

        $plan = $response->viewData('plan');

        $this->assertSame($this->night->id, $plan[$this->employee->id]['2026-08-04']->shift_id);
        $this->assertSame(1, $response->viewData('plannedCount'));
        $this->assertSame(1, $response->viewData('unpublishedCount'));
    }
}
