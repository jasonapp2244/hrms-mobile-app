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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clocking in and out from the app, and reading the day back.
 *
 * The punch rules themselves are covered by the service's own tests; what
 * matters here is that the API applies the same ones rather than a second set.
 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $shift;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ',
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** A punch already on record, without going through the endpoint. */
    protected function punch(string $type, string $at, array $overrides = []): AttendanceLog
    {
        $moment = Carbon::parse($at);

        return AttendanceLog::create(array_merge([
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => $type,
            'scanned_at'  => $moment,
            'work_date'   => $moment->toDateString(),
            'status'      => 'ontime',
            'source'      => 'mobile',
        ], $overrides));
    }

    // ================= check =================

    public function test_the_first_punch_of_the_day_is_a_clock_in(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));

        $this->postJson('/api/v1/attendance/check')
            ->assertOk()
            ->assertJsonPath('punch.type', 'in')
            ->assertJsonPath('punch.status', 'ontime')
            ->assertJsonPath('next_action', 'out');
    }

    public function test_the_server_decides_in_or_out_not_the_app(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertOk();

        // An app that thinks it is still clocking in must not be able to say so.
        $this->travelTo(Carbon::parse('2026-08-03 17:10:00'));

        $this->postJson('/api/v1/attendance/check', ['type' => 'in'])
            ->assertOk()
            ->assertJsonPath('punch.type', 'out')
            ->assertJsonPath('next_action', 'in');
    }

    public function test_arriving_after_the_grace_period_is_late(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:20:00'));

        $this->postJson('/api/v1/attendance/check')
            ->assertJsonPath('punch.status', 'late');
    }

    public function test_the_device_clock_is_not_trusted(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:20:00'));

        // Claiming an earlier time must not turn a late arrival into an on-time one.
        $this->postJson('/api/v1/attendance/check', ['scanned_at' => '2026-08-03 08:55:00'])
            ->assertJsonPath('punch.status', 'late');

        $this->assertSame('09:20:00', AttendanceLog::first()->scanned_at->format('H:i:s'));
    }

    public function test_gps_is_recorded_but_never_blocks_a_punch(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));

        // Nowhere near the office — still a valid punch. Remote and hybrid staff
        // clock in from wherever they are.
        $this->postJson('/api/v1/attendance/check', [
            'latitude' => 51.5074, 'longitude' => -0.1278,
        ])->assertOk();

        $log = AttendanceLog::first();
        $this->assertEquals(51.5074, $log->latitude);
        $this->assertEquals(-0.1278, $log->longitude);
    }

    public function test_a_nonsense_coordinate_is_rejected(): void
    {
        $this->postJson('/api/v1/attendance/check', ['latitude' => 999])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_a_double_tap_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertOk();

        $this->postJson('/api/v1/attendance/check')
            ->assertStatus(429)
            ->assertJsonPath('error', 'duplicate_scan');

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_the_punch_is_tagged_to_the_employees_office(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertJsonPath('punch.office', 'HQ');
    }

    public function test_someone_with_no_office_of_their_own_still_gets_one(): void
    {
        $this->employee->update(['office_id' => null]);
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));

        // Remote staff have no desk; the company's first office is the record.
        $this->postJson('/api/v1/attendance/check')
            ->assertOk()
            ->assertJsonPath('punch.office', 'HQ');
    }

    public function test_a_company_with_no_office_at_all_says_so(): void
    {
        $this->employee->update(['office_id' => null]);
        $this->office->delete();

        $this->postJson('/api/v1/attendance/check')
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_office');
    }

    public function test_a_punch_records_the_company_it_belongs_to(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertOk();

        // A row with no company is invisible to every company-scoped query —
        // the dashboard tiles, the reports, the whole staff-facing side.
        $this->assertSame($this->company->id, AttendanceLog::first()->company_id);
    }

    public function test_a_punch_created_without_a_company_still_gets_one(): void
    {
        // Seeders, imports and the portal button all create logs directly. The
        // model fills it in so a caller that forgets cannot produce an orphan.
        $log = AttendanceLog::create([
            'employee_id' => $this->employee->id, 'office_id' => $this->office->id,
            'type' => 'in', 'scanned_at' => '2026-08-03 09:00:00',
            'work_date' => '2026-08-03', 'status' => 'ontime', 'source' => 'button',
        ]);

        $this->assertSame($this->company->id, $log->company_id);
    }

    public function test_the_punch_is_marked_as_coming_from_the_app(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertOk();

        // HR needs to be able to tell an app punch from a web one.
        $this->assertSame('mobile', AttendanceLog::first()->source);
    }

    public function test_an_account_with_no_employee_record_cannot_punch(): void
    {
        $orphan = User::create([
            'name' => 'Nobody', 'email' => 'nobody@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        Sanctum::actingAs($orphan);

        $this->postJson('/api/v1/attendance/check')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_checking_in_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/v1/attendance/check')
            ->assertStatus(401);
    }

    // ================= today =================

    public function test_today_reports_an_empty_day(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-03')
            ->assertJsonPath('next_action', 'in')
            ->assertJsonPath('is_clocked_in', false)
            ->assertJsonPath('worked_minutes', 0)
            ->assertJsonCount(0, 'punches');
    }

    public function test_today_counts_the_hours_worked_so_far_while_still_clocked_in(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 11:30:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('worked_minutes', 150)
            ->assertJsonPath('is_clocked_in', true)
            ->assertJsonPath('next_action', 'out');
    }

    public function test_today_stops_counting_once_clocked_out(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 12:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 15:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('worked_minutes', 180)
            ->assertJsonPath('is_clocked_in', false)
            ->assertJsonCount(2, 'punches');
    }

    public function test_today_adds_up_a_split_day(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 12:00:00');
        $this->punch('in', '2026-08-03 13:00:00');
        $this->punch('out', '2026-08-03 17:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->getJson('/api/v1/attendance/today')->assertJsonPath('worked_minutes', 420);
    }

    public function test_today_carries_the_shift_the_hours_are_judged_against(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('shift.name', 'Day')
            ->assertJsonPath('shift.late_grace_minutes', 15)
            ->assertJsonPath('shift.crosses_midnight', false);
    }

    public function test_today_shows_the_rostered_shift_over_the_standing_one(): void
    {
        $night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $night->id, 'date' => '2026-08-03', 'published_at' => now(),
        ]);

        $this->travelTo(Carbon::parse('2026-08-03 20:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('shift.name', 'Night')
            ->assertJsonPath('shift.crosses_midnight', true);
    }

    public function test_a_night_worker_after_midnight_still_sees_the_day_they_started(): void
    {
        $night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 0, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);
        $this->employee->update(['shift_id' => $night->id]);

        $this->punch('in', '2026-08-03 22:00:00');
        $this->travelTo(Carbon::parse('2026-08-04 02:00:00'));

        // 02:00 on the 4th is still the 3rd's shift — the screen has to agree
        // with the day the punch was filed against.
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('date', '2026-08-03')
            ->assertJsonPath('is_clocked_in', true)
            ->assertJsonPath('worked_minutes', 240);
    }

    public function test_today_says_when_the_day_is_rostered_off(): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-03', 'is_day_off' => true, 'published_at' => now(),
        ]);

        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('is_day_off', true)
            ->assertJsonPath('shift', null);
    }

    public function test_today_names_a_holiday(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day',
            'date' => '2026-08-03', 'is_recurring' => false,
        ]);

        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        $this->getJson('/api/v1/attendance/today')->assertJsonPath('holiday', 'Founders Day');
    }

    public function test_today_shows_approved_leave_but_still_allows_a_punch(): void
    {
        $this->approvedLeave('2026-08-03', '2026-08-05');
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('leave.type', 'Annual')
            ->assertJsonPath('leave.end_date', '2026-08-05')
            // Somebody who comes in anyway is present, not on leave — the button
            // stays live.
            ->assertJsonPath('can_check', true);
    }

    public function test_can_check_goes_false_during_the_cooldown(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:50:00'));
        $this->postJson('/api/v1/attendance/check')->assertOk();

        $this->getJson('/api/v1/attendance/today')->assertJsonPath('can_check', false);
    }

    public function test_a_company_off_utc_does_not_accrue_the_offset_as_hours_worked(): void
    {
        // scanned_at is written in the company's timezone but read back as if it
        // were the app's. Comparing that to a real now() counted the offset as
        // time on the clock: a punch one second old read as four hours in New
        // York. Everything else in this file runs on a UTC company, where the
        // offset is zero and the bug is invisible.
        $this->company->update(['timezone' => 'America/New_York']);
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00', 'America/New_York'));

        $this->postJson('/api/v1/attendance/check')->assertOk();

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('worked_minutes', 0);
    }

    public function test_a_punch_is_served_with_the_companys_offset(): void
    {
        $this->company->update(['timezone' => 'America/New_York']);
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00', 'America/New_York'));

        // A phone renders an ISO string in its own zone, so a bare offset would
        // put the punch hours away from when it was actually made.
        $scannedAt = $this->postJson('/api/v1/attendance/check')->json('punch.scanned_at');

        $this->assertStringContainsString('13:00:00-04:00', $scannedAt);
    }

    // ================= history =================

    public function test_history_defaults_to_the_last_thirty_days(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00'));

        $response = $this->getJson('/api/v1/attendance/history')->assertOk();

        $this->assertSame('2026-07-12', $response->json('from'));
        $this->assertSame('2026-08-10', $response->json('to'));
        $this->assertCount(30, $response->json('days'));
    }

    public function test_history_returns_one_row_per_day_newest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $response = $this->getJson('/api/v1/attendance/history?from=2026-08-03&to=2026-08-05');

        $this->assertSame(
            ['2026-08-05', '2026-08-04', '2026-08-03'],
            array_column($response->json('days'), 'date'),
        );
    }

    public function test_a_day_with_punches_is_present_with_its_hours(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:00:00');
        $this->travelTo(Carbon::parse('2026-08-04 12:00:00'));

        $day = $this->dayFrom('2026-08-03', '2026-08-03');

        $this->assertSame('present', $day['status']);
        $this->assertSame(480, $day['worked_minutes']);
        $this->assertSame(2, $day['punches']);
        $this->assertFalse($day['late']);
    }

    public function test_a_late_arrival_is_flagged(): void
    {
        $this->punch('in', '2026-08-03 09:30:00', ['status' => 'late']);
        $this->travelTo(Carbon::parse('2026-08-04 12:00:00'));

        $this->assertTrue($this->dayFrom('2026-08-03', '2026-08-03')['late']);
    }

    public function test_a_day_never_clocked_out_of_reports_no_hours(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        // Present — they were here — but there is no honest number of hours to
        // put against a stretch that was never closed.
        $day = $this->dayFrom('2026-08-03', '2026-08-03');
        $this->assertSame('present', $day['status']);
        $this->assertSame(0, $day['worked_minutes']);
    }

    public function test_a_missed_working_day_is_an_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $this->assertSame('absent', $this->dayFrom('2026-08-03', '2026-08-03')['status']);
    }

    public function test_a_weekend_is_not_an_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00'));

        // 2026-08-08 is a Saturday.
        $this->assertSame('weekend', $this->dayFrom('2026-08-08', '2026-08-08')['status']);
    }

    public function test_a_holiday_is_not_an_absence(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day',
            'date' => '2026-08-03', 'is_recurring' => false,
        ]);
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $day = $this->dayFrom('2026-08-03', '2026-08-03');
        $this->assertSame('holiday', $day['status']);
        $this->assertSame('Founders Day', $day['holiday']);
    }

    public function test_approved_leave_is_not_an_absence(): void
    {
        $this->approvedLeave('2026-08-03', '2026-08-04');
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));

        $this->assertSame('leave', $this->dayFrom('2026-08-03', '2026-08-03')['status']);
    }

    public function test_a_rostered_day_off_is_not_an_absence(): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-03', 'is_day_off' => true, 'published_at' => now(),
        ]);
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $this->assertSame('day_off', $this->dayFrom('2026-08-03', '2026-08-03')['status']);
    }

    public function test_turning_up_on_a_booked_day_off_counts_as_present(): void
    {
        $this->approvedLeave('2026-08-03', '2026-08-03');
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        // They worked. Whatever the calendar said, the record has to say so.
        $this->assertSame('present', $this->dayFrom('2026-08-03', '2026-08-03')['status']);
    }

    public function test_history_totals_add_up(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:00:00');
        $this->punch('in', '2026-08-04 09:30:00', ['status' => 'late']);
        $this->punch('out', '2026-08-04 17:00:00');
        $this->approvedLeave('2026-08-05', '2026-08-05');

        $this->travelTo(Carbon::parse('2026-08-07 12:00:00'));

        $totals = $this->getJson('/api/v1/attendance/history?from=2026-08-03&to=2026-08-07')
            ->json('totals');

        $this->assertSame(2, $totals['present_days']);
        $this->assertSame(1, $totals['late_days']);
        $this->assertSame(1, $totals['leave_days']);
        $this->assertSame(2, $totals['absent_days']);   // the 6th and the 7th
        $this->assertSame(930, $totals['worked_minutes']);
    }

    public function test_history_never_reports_the_future_as_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $response = $this->getJson('/api/v1/attendance/history?from=2026-08-03&to=2026-08-31')
            ->assertOk();

        // A day that has not happened cannot be an absence, so the window stops
        // at today rather than filling in a fortnight of them.
        $this->assertSame('2026-08-05', $response->json('to'));
        $this->assertSame('2026-08-05', $response->json('days.0.date'));
    }

    public function test_a_backwards_range_is_rejected(): void
    {
        $this->getJson('/api/v1/attendance/history?from=2026-08-10&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_range');
    }

    public function test_an_enormous_range_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        $this->getJson('/api/v1/attendance/history?from=2020-01-01&to=2026-08-05')
            ->assertStatus(422)
            ->assertJsonPath('error', 'range_too_large');
    }

    public function test_a_malformed_date_is_rejected(): void
    {
        $this->getJson('/api/v1/attendance/history?from=last-tuesday')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_history_shows_only_the_callers_own_days(): void
    {
        $other = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'employee_code' => 'E2', 'first_name' => 'Bob', 'last_name' => 'Ray',
            'status' => 'active',
        ]);

        AttendanceLog::create([
            'employee_id' => $other->id, 'office_id' => $this->office->id,
            'type' => 'in', 'scanned_at' => '2026-08-03 09:00:00',
            'work_date' => '2026-08-03', 'status' => 'ontime', 'source' => 'mobile',
        ]);

        $this->travelTo(Carbon::parse('2026-08-05 12:00:00'));

        // Bob was in. Ann was not, and must not inherit his day.
        $this->assertSame('absent', $this->dayFrom('2026-08-03', '2026-08-03')['status']);
    }

    // ================= helpers =================

    protected function dayFrom(string $from, string $to): array
    {
        return $this->getJson("/api/v1/attendance/history?from={$from}&to={$to}")
            ->assertOk()
            ->json('days.0');
    }

    protected function approvedLeave(string $from, string $to): LeaveRequest
    {
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual',
            'days_per_year' => 20, 'is_active' => true, 'requires_approval' => true,
        ]);

        return LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
            'days' => 1, 'status' => 'approved',
        ]);
    }
}
