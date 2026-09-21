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
 * The day a punch is judged against when nobody rostered one.
 *
 * `determineStatus()` needs a start, an end and a grace window. A rostered
 * shift supplies all three, and on any day one is assigned this file has
 * nothing to say. The gap is the day nobody planned: an employee whose
 * department carries no shift, or one who comes in on a rostered day off,
 * where `shiftOn()` correctly answers null.
 *
 * Until now that fell through to a literal 09:00–17:00 with fifteen minutes'
 * grace, written into the service. It is the only business rule in the
 * codebase that was not configurable, and it is wrong for every company that
 * does not work those hours: a cleaning firm starting at six had its early
 * shift marked late every time somebody punched on an unrostered day, and no
 * setting anywhere would have moved it.
 *
 * It is a company setting now, beside the working week and the eight policies
 * that were already there — per company rather than per installation, because
 * on a multi-company box one client's ordinary morning is another's overtime.
 *
 * **The default is unchanged.** A company that sets nothing still gets
 * 09:00–17:00 / 15, which is what every existing row is being judged by today;
 * this change must not restate anybody's history.
 */
class UnrosteredDayPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // UTC deliberately: the timezone handling in determineStatus is proven
        // elsewhere, and a second zone here would only obscure which value the
        // status came from.
        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        // No shift on the department, and none on the employee below. This is
        // the state the whole file is about: shiftOn() answers null and there
        // is nothing to measure the punch against but the company's default.
        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->employee = $this->makeEmployee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeEmployee(): Employee
    {
        $user = User::create([
            'name' => 'Ann', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        return Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $user->id,
            'employee_code' => 'EMP-0001', 'first_name' => 'Ann', 'last_name' => 'Tester',
            'status' => 'active',
        ]);
    }

    /** Set one or more policy keys on the company and re-read it. */
    private function policy(array $settings): void
    {
        $this->company->update([
            'settings' => array_merge($this->company->settings ?? [], $settings),
        ]);

        $this->employee->refresh()->load('company');
    }

    /** Punch at a wall-clock time and hand back the status that was stored. */
    private function punchAt(string $dateTime): string
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'UTC'));

        app(AttendanceService::class)->record($this->employee->fresh(), $this->office);

        return AttendanceLog::orderByDesc('id')->value('status');
    }

    public function test_an_unrostered_arrival_is_judged_against_the_companys_own_start(): void
    {
        // Six in the morning is this company's ordinary start. Under the old
        // literal the day began at nine, so 06:20 was comfortably "ontime" —
        // an early shift that could never be recorded as late at all.
        $this->policy(['default_day_start' => '06:00:00']);

        $this->assertSame('late', $this->punchAt('2026-04-06 06:20:00'));
    }

    public function test_an_unrostered_arrival_inside_the_companys_start_is_on_time(): void
    {
        // The other half of the same setting: moving the start must not make
        // everybody late, only the people who are.
        $this->policy(['default_day_start' => '06:00:00']);

        $this->assertSame('ontime', $this->punchAt('2026-04-06 05:58:00'));
    }

    public function test_the_grace_window_on_an_unrostered_day_is_the_companys_own(): void
    {
        // Thirty minutes' grace, so 09:20 is inside it. The old fifteen made
        // this exact punch late, which is the mutation that kills this test.
        $this->policy(['default_day_grace_minutes' => 30]);

        $this->assertSame('ontime', $this->punchAt('2026-04-06 09:20:00'));
    }

    public function test_an_unrostered_departure_is_judged_against_the_companys_own_end(): void
    {
        // A company whose day ends at three. Leaving at 15:30 is going home
        // late, not early — the old literal called it early_leave until five.
        $this->policy([
            'default_day_start' => '07:00:00',
            'default_day_end'   => '15:00:00',
        ]);

        $this->punchAt('2026-04-06 07:00:00');

        $this->assertSame('ontime', $this->punchAt('2026-04-06 15:30:00'));
    }

    public function test_leaving_before_the_companys_end_is_still_an_early_leave(): void
    {
        $this->policy([
            'default_day_start' => '07:00:00',
            'default_day_end'   => '15:00:00',
        ]);

        $this->punchAt('2026-04-06 07:00:00');

        $this->assertSame('early_leave', $this->punchAt('2026-04-06 14:30:00'));
    }

    public function test_a_company_that_sets_nothing_still_gets_nine_to_five(): void
    {
        // The guard on everybody's existing data. No settings written at all,
        // and 09:20 must stay late exactly as it is today.
        $this->assertSame('late', $this->punchAt('2026-04-06 09:20:00'));
    }

    public function test_a_company_that_sets_nothing_still_gets_fifteen_minutes_grace(): void
    {
        $this->assertSame('ontime', $this->punchAt('2026-04-06 09:12:00'));
    }

    public function test_a_rostered_shift_still_outranks_the_company_default(): void
    {
        // The setting is a fallback and must stay one. With a shift on the
        // roster the company default is not consulted at all — if it were,
        // this punch would be four hours late.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $shift->id, 'date' => '2026-04-06', 'published_at' => now(),
        ]);

        $this->policy(['default_day_start' => '05:00:00']);

        $this->assertSame('ontime', $this->punchAt('2026-04-06 09:10:00'));
    }

    public function test_somebody_working_a_rostered_day_off_is_judged_by_the_company_default(): void
    {
        // A day off is not a shift, so shiftOn() answers null and this lands
        // on the same fallback as an unplanned day. Worth pinning separately:
        // it is the route into this code that has nothing to do with whether
        // a department carries a shift.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $shift->id, 'date' => '2026-04-06',
            'is_day_off' => true, 'published_at' => now(),
        ]);

        $this->policy(['default_day_start' => '06:00:00']);

        $this->assertSame('late', $this->punchAt('2026-04-06 06:20:00'));
    }

    public function test_one_company_default_does_not_reach_another_company(): void
    {
        // The reason this is a company setting and not a config key. Two
        // clients on one box keep different hours, and the second must not be
        // judged by the first's morning.
        $this->policy(['default_day_start' => '06:00:00']);

        $other = Company::create([
            'name' => 'Beta', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $otherOffice = Office::create([
            'company_id' => $other->id, 'name' => 'Beta Office',
        ]);
        $otherDepartment = Department::create([
            'company_id' => $other->id, 'name' => 'Beta Ops',
        ]);
        $otherUser = User::create([
            'name' => 'Bob', 'email' => 'bob@beta.test',
            'password' => Hash::make('password'), 'company_id' => $other->id,
        ]);
        $otherUser->assignRole('employee');
        $otherEmployee = Employee::create([
            'company_id' => $other->id, 'department_id' => $otherDepartment->id,
            'office_id' => $otherOffice->id, 'user_id' => $otherUser->id,
            'employee_code' => 'BETA-0001', 'first_name' => 'Bob', 'last_name' => 'Tester',
            'status' => 'active',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-06 06:20:00', 'UTC'));
        app(AttendanceService::class)->record($otherEmployee, $otherOffice);

        // Beta set nothing, so Beta's morning is still nine o'clock.
        $this->assertSame('ontime', AttendanceLog::where('employee_id', $otherEmployee->id)->value('status'));
    }

    // -------------------------------------------------------------------------
    // The Policies screen — A2.9
    //
    // A setting nobody can reach is the same as a literal, which is what these
    // three were. The form is the point of the change.
    // -------------------------------------------------------------------------

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    /** The four policy fields that were already required, at their defaults. */
    private function existingFields(): array
    {
        return [
            'checkin_reminder_before_minutes' => 10,
            'checkout_reminder_after_minutes' => 30,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
        ];
    }

    public function test_an_admin_can_set_the_companys_default_day(): void
    {
        $this->actingAs($this->admin())->put(route('policies.update'), $this->existingFields() + [
            'default_day_start' => '06:00',
            'default_day_end' => '14:00',
            'default_day_grace_minutes' => 20,
        ])->assertRedirect()->assertSessionHas('success');

        $company = $this->company->fresh();

        $this->assertSame('06:00:00', $company->policy('default_day_start'));
        $this->assertSame('14:00:00', $company->policy('default_day_end'));
        $this->assertSame(20, $company->policy('default_day_grace_minutes'));
    }

    public function test_a_default_day_that_ends_before_it_starts_is_refused(): void
    {
        // Not a hypothetical typo: the two fields sit next to each other and a
        // night operation is the obvious thing somebody would try to express
        // here. It cannot be — an unrostered day has no roster row saying which
        // calendar day an overnight span belongs to, so determineStatus would
        // measure the evening against tomorrow morning. Refused with a reason
        // rather than stored and quietly wrong.
        $this->actingAs($this->admin())->put(route('policies.update'), $this->existingFields() + [
            'default_day_start' => '22:00',
            'default_day_end' => '06:00',
            'default_day_grace_minutes' => 15,
        ])->assertRedirect()->assertSessionHas('error');

        $company = $this->company->fresh();

        $this->assertSame('09:00:00', $company->policy('default_day_start'));
        $this->assertSame('17:00:00', $company->policy('default_day_end'));
    }

    public function test_the_policies_screen_shows_the_default_day(): void
    {
        $this->policy([
            'default_day_start' => '06:30:00',
            'default_day_end' => '14:30:00',
            'default_day_grace_minutes' => 25,
        ]);

        $this->actingAs($this->admin())->get(route('policies.edit'))
            ->assertOk()
            ->assertSee('default_day_start')
            ->assertSee('06:30')
            ->assertSee('14:30')
            ->assertSee('25');
    }

    public function test_a_grace_window_longer_than_the_day_is_refused(): void
    {
        // Grace that outruns the day marks nobody late, ever. The shift form
        // already caps late_grace_minutes at 120 and this is the same rule for
        // the same reason.
        $this->actingAs($this->admin())->put(route('policies.update'), $this->existingFields() + [
            'default_day_start' => '09:00',
            'default_day_end' => '17:00',
            'default_day_grace_minutes' => 600,
        ])->assertRedirect()->assertSessionHasErrors('default_day_grace_minutes');

        $this->assertSame(15, $this->company->fresh()->policy('default_day_grace_minutes'));
    }
}
