<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The days ahead as the employee sees them.
 */
class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Shift $day;
    protected Shift $night;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->day = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day', 'color' => '#00aa00',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night', 'color' => '#000088',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'user_id' => $this->user->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);

        // A Monday.
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        Sanctum::actingAs($this->user);
    }

    protected function roster(string $date, ?Shift $shift, bool $published = true, bool $dayOff = false): ShiftAssignment
    {
        return ShiftAssignment::create([
            'company_id'   => $this->company->id,
            'employee_id'  => $this->employee->id,
            'shift_id'     => $shift?->id,
            'date'         => $date,
            'is_day_off'   => $dayOff,
            'published_at' => $published ? now() : null,
        ]);
    }

    protected function day(string $date): array
    {
        return collect($this->getJson("/api/v1/schedule?from={$date}&to={$date}")->assertOk()->json('days'))
            ->first();
    }

    // ================= window =================

    public function test_the_default_window_is_the_next_fortnight(): void
    {
        $response = $this->getJson('/api/v1/schedule')->assertOk();

        $this->assertSame('2026-08-03', $response->json('from'));
        $this->assertSame('2026-08-16', $response->json('to'));
        $this->assertCount(14, $response->json('days'));
    }

    public function test_the_window_runs_oldest_first(): void
    {
        // A schedule is read forwards — unlike history, which is read back.
        $dates = array_column($this->getJson('/api/v1/schedule?from=2026-08-03&to=2026-08-05')->json('days'), 'date');

        $this->assertSame(['2026-08-03', '2026-08-04', '2026-08-05'], $dates);
    }

    public function test_a_backwards_range_is_rejected(): void
    {
        $this->getJson('/api/v1/schedule?from=2026-08-10&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_range');
    }

    public function test_an_enormous_range_is_rejected(): void
    {
        $this->getJson('/api/v1/schedule?from=2026-01-01&to=2026-12-31')
            ->assertStatus(422)
            ->assertJsonPath('error', 'range_too_large');
    }

    public function test_a_malformed_date_is_rejected(): void
    {
        $this->getJson('/api/v1/schedule?from=next-week')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_the_schedule_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer nope')
            ->getJson('/api/v1/schedule')
            ->assertStatus(401);
    }

    // ================= what a day says =================

    public function test_an_unplanned_day_falls_back_to_the_standing_shift(): void
    {
        $day = $this->day('2026-08-03');

        $this->assertSame('Day', $day['shift']['name']);
        $this->assertFalse($day['is_rostered']);
    }

    public function test_the_standing_shift_is_reported_once_for_the_whole_window(): void
    {
        // So the app can say "your usual hours" rather than repeating it per row.
        $this->getJson('/api/v1/schedule')
            ->assertJsonPath('standing_shift.name', 'Day');
    }

    public function test_a_planned_day_overrides_the_standing_shift(): void
    {
        $this->roster('2026-08-04', $this->night);

        $day = $this->day('2026-08-04');

        // Without this a rotation could not be expressed at all — every week
        // would look identical.
        $this->assertSame('Night', $day['shift']['name']);
        $this->assertTrue($day['is_rostered']);
    }

    public function test_a_rostered_day_off_shows_no_hours(): void
    {
        $this->roster('2026-08-04', null, dayOff: true);

        $day = $this->day('2026-08-04');

        $this->assertTrue($day['is_day_off']);
        $this->assertNull($day['shift']);
    }

    public function test_a_planned_day_off_is_not_the_same_as_an_unplanned_day(): void
    {
        $this->roster('2026-08-04', null, dayOff: true);

        // Both have no rostered shift, but only one means "you are not on".
        $this->assertTrue($this->day('2026-08-04')['is_day_off']);
        $this->assertFalse($this->day('2026-08-05')['is_day_off']);
        $this->assertSame('Day', $this->day('2026-08-05')['shift']['name']);
    }

    public function test_a_draft_day_is_invisible(): void
    {
        $this->roster('2026-08-04', $this->night, published: false);

        // Staff watching draft days move around is the problem publishing exists
        // to prevent, so an unpublished plan falls back to the standing shift.
        $day = $this->day('2026-08-04');

        $this->assertSame('Day', $day['shift']['name']);
        $this->assertFalse($day['is_rostered']);
    }

    public function test_a_weekend_is_marked_as_a_non_working_day(): void
    {
        // 2026-08-08 is a Saturday.
        $this->assertFalse($this->day('2026-08-08')['is_working_day']);
        $this->assertTrue($this->day('2026-08-07')['is_working_day']);
    }

    public function test_the_standing_shift_does_not_leak_onto_a_weekend(): void
    {
        // Nobody is on 09:00–17:00 on a Saturday just because that is their
        // usual shift. Serving it would have a client print hours the employee
        // is not expected for.
        $this->assertNull($this->day('2026-08-08')['shift']);
        $this->assertSame('Day', $this->day('2026-08-07')['shift']['name']);
    }

    public function test_the_standing_shift_does_not_leak_onto_a_holiday(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day',
            'date' => '2026-08-05', 'is_recurring' => false,
        ]);

        $this->assertNull($this->day('2026-08-05')['shift']);
    }

    public function test_a_weekend_somebody_was_rostered_onto_still_shows_its_shift(): void
    {
        $this->roster('2026-08-08', $this->night);

        // The plan wins where there is one — that is the whole point of putting
        // somebody on a Saturday.
        $this->assertSame('Night', $this->day('2026-08-08')['shift']['name']);
    }

    public function test_a_holiday_is_named_on_the_day_it_falls(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day',
            'date' => '2026-08-05', 'is_recurring' => false,
        ]);

        $day = $this->day('2026-08-05');

        $this->assertSame('Founders Day', $day['holiday']);
        $this->assertFalse($day['is_working_day']);
    }

    public function test_approved_leave_is_shown_against_every_day_it_covers(): void
    {
        $this->approvedLeave('2026-08-04', '2026-08-06');

        $this->assertSame('Annual', $this->day('2026-08-04')['leave']);
        $this->assertSame('Annual', $this->day('2026-08-06')['leave']);
        $this->assertNull($this->day('2026-08-07')['leave']);
    }

    public function test_leave_still_awaiting_a_decision_is_not_shown_as_booked(): void
    {
        $this->approvedLeave('2026-08-04', '2026-08-05', 'pending');

        // Nothing has been granted yet — showing it as time off would tell the
        // employee they are away when they are expected in.
        $this->assertNull($this->day('2026-08-04')['leave']);
    }

    public function test_leave_starting_before_the_window_still_covers_the_days_inside_it(): void
    {
        $this->approvedLeave('2026-07-30', '2026-08-04');

        $this->assertSame('Annual', $this->day('2026-08-03')['leave']);
    }

    public function test_a_rostered_shift_and_leave_are_both_reported(): void
    {
        $this->roster('2026-08-04', $this->night);
        $this->approvedLeave('2026-08-04', '2026-08-04');

        // The app decides how to show the two together; the API does not hide
        // the shift somebody is booked off from.
        $day = $this->day('2026-08-04');

        $this->assertSame('Night', $day['shift']['name']);
        $this->assertSame('Annual', $day['leave']);
    }

    public function test_the_schedule_is_only_ever_the_callers_own(): void
    {
        $other = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'E2',
            'first_name' => 'Bob', 'last_name' => 'Ray', 'status' => 'active',
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $other->id,
            'shift_id' => $this->night->id, 'date' => '2026-08-04', 'published_at' => now(),
        ]);

        // Bob is on nights. Ann must not inherit his roster.
        $this->assertFalse($this->day('2026-08-04')['is_rostered']);
    }

    public function test_an_employee_with_no_shift_at_all_reports_nothing_rather_than_failing(): void
    {
        $this->employee->update(['department_id' => null]);

        $response = $this->getJson('/api/v1/schedule')->assertOk();

        $this->assertNull($response->json('standing_shift'));
        $this->assertNull($response->json('days.0.shift'));
    }

    protected function approvedLeave(string $from, string $to, string $status = 'approved'): LeaveRequest
    {
        $type = LeaveType::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Annual'],
            ['days_per_year' => 20, 'is_active' => true, 'requires_approval' => true],
        );

        return LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
            'days' => 1, 'status' => $status,
        ]);
    }
}
