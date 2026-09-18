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
            ->assertJsonPath('error', 'no_employee_record');
    }

    public function test_checking_in_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/v1/attendance/check')
            ->assertStatus(401);
    }

    // ================= offline sync =================

    public function test_a_punch_made_offline_keeps_the_time_it_was_made(): void
    {
        // The whole point. Stamping it on arrival would file a 09:00 check-in
        // as 13:00 and hand payroll a number that is simply wrong.
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:00:00']],
        ])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('results.0.result', 'accepted')
            ->assertJsonPath('results.0.punch.type', 'in');

        $log = AttendanceLog::firstOrFail();

        $this->assertSame('2026-08-03 09:00:00', $log->scanned_at->format('Y-m-d H:i:s'));
        $this->assertSame('mobile_offline', $log->source);
    }

    public function test_an_offline_punch_says_how_long_it_sat_on_the_handset(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 13:20:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:00:00']],
        ])->assertOk();

        // The first thing a reader wants to know about a back-dated row.
        $this->assertStringContainsString('4h 20m later', AttendanceLog::firstOrFail()->notes);
    }

    public function test_an_offline_punch_is_judged_against_the_shift_at_that_moment(): void
    {
        // 09:40 against a 09:00 shift with 15 minutes' grace is late, however
        // long afterwards it was delivered.
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:40:00']],
        ])
            ->assertOk()
            ->assertJsonPath('results.0.punch.status', 'late');
    }

    public function test_a_queue_is_applied_oldest_first_whatever_order_it_arrives(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        // Deliberately out of order: each punch's direction is decided by what
        // precedes it, so applying them as sent would have the later one decide
        // before the earlier one existed.
        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [
                ['occurred_at' => '2026-08-03 17:00:00'],
                ['occurred_at' => '2026-08-03 09:00:00'],
            ],
        ])->assertOk()->assertJsonPath('accepted', 2);

        $logs = AttendanceLog::orderBy('scanned_at')->get();

        $this->assertSame('in', $logs[0]->type);
        $this->assertSame('out', $logs[1]->type);
    }

    public function test_an_offline_punch_slots_in_behind_one_already_recorded(): void
    {
        // A live check-out at 17:00 reached the server first; the offline
        // check-in at 09:00 arrives afterwards and must still read as an "in".
        $this->travelTo(Carbon::parse('2026-08-03 17:00:00'));
        $this->punch('out', '2026-08-03 17:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:00:00']],
        ])
            ->assertOk()
            ->assertJsonPath('results.0.punch.type', 'in');
    }

    public function test_delivering_the_same_punch_twice_does_not_double_it(): void
    {
        // A queue retries whenever the connection is flaky, which is exactly
        // when this feature is in use — and attendance is append-only, so a
        // duplicate could only ever be voided, never removed.
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $payload = ['punches' => [['occurred_at' => '2026-08-03 09:00:00']]];

        $this->postJson('/api/v1/attendance/sync', $payload)
            ->assertOk()->assertJsonPath('results.0.result', 'accepted');

        $this->postJson('/api/v1/attendance/sync', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.result', 'duplicate')
            ->assertJsonPath('duplicate', 1);

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_a_punch_dated_in_the_future_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 11:00:00']],
        ])
            ->assertOk()
            ->assertJsonPath('results.0.result', 'refused')
            ->assertJsonPath('refused', 1);

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_a_punch_older_than_the_cap_is_refused_rather_than_clamped(): void
    {
        // Clamping would write a wrong time that looks right. Past the cap the
        // claim is old enough that somebody should look at it — A4.13 exists
        // for that, and carries a reason and a decision.
        $this->travelTo(Carbon::parse('2026-08-06 09:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:00:00']],
        ])
            ->assertOk()
            ->assertJsonPath('results.0.result', 'refused');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_one_refused_punch_does_not_throw_away_the_good_ones(): void
    {
        // Partial success is the normal case. A single verdict for the batch
        // would leave the app guessing which entries to drop, and guessing
        // means losing somebody's hours or punching them in twice.
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [
                ['occurred_at' => '2026-08-03 09:00:00'],
                ['occurred_at' => '2026-08-03 23:00:00'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('refused', 1);

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_gps_travels_with_an_offline_punch(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [[
                'occurred_at' => '2026-08-03 09:00:00',
                'latitude' => 40.7128, 'longitude' => -74.0060,
            ]],
        ])->assertOk();

        $log = AttendanceLog::firstOrFail();

        $this->assertEquals(40.7128, (float) $log->latitude);
        $this->assertEquals(-74.0060, (float) $log->longitude);
    }

    public function test_an_empty_or_oversized_queue_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/sync', ['punches' => []])
            ->assertStatus(422)->assertJsonValidationErrors('punches');

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => array_fill(0, 51, ['occurred_at' => '2026-08-03 09:00:00']),
        ])->assertStatus(422)->assertJsonValidationErrors('punches');
    }

    public function test_syncing_needs_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-08-03 09:00:00']],
        ])->assertStatus(401);
    }

    // ================= break =================

    public function test_a_break_starts_when_on_the_clock(): void
    {
        // travelTo before the punch, not after: the duplicate cooldown measures
        // created_at, which is stamped from the clock as it stands when the row
        // is written. Punch first and the row is stamped today, which is not
        // within a minute of a moment in August — and the endpoint refuses.
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/break')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('punch.type', 'break_start')
            ->assertJsonPath('on_break', true)
            ->assertJsonPath('next_break_action', 'end');
    }

    public function test_the_server_decides_start_or_end_not_the_app(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));
        $this->punch('break_start', '2026-08-03 13:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:30:00'));

        // The app sends nothing but coordinates, exactly as it does for a
        // punch, so a screen that has not refreshed cannot open a second break.
        $this->postJson('/api/v1/attendance/break')
            ->assertOk()
            ->assertJsonPath('punch.type', 'break_end')
            ->assertJsonPath('on_break', false)
            ->assertJsonPath('next_break_action', 'start');
    }

    public function test_a_break_is_refused_when_not_clocked_in(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/break')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'break_not_available');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_a_break_after_clocking_out_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 17:00:00'));
        $this->punch('out', '2026-08-03 17:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 17:30:00'));

        $this->postJson('/api/v1/attendance/break')
            ->assertStatus(422)
            ->assertJsonPath('error', 'break_not_available');
    }

    public function test_a_break_punch_is_never_late(): void
    {
        // There is nothing to judge a break against. Reusing the punch statuses
        // would hang a "late" badge on somebody's lunch.
        $this->travelTo(Carbon::parse('2026-08-03 11:00:00'));
        $this->punch('in', '2026-08-03 11:00:00', ['status' => 'late']);
        $this->travelTo(Carbon::parse('2026-08-03 14:00:00'));

        $this->postJson('/api/v1/attendance/break')
            ->assertOk()
            ->assertJsonPath('punch.status', 'ontime');
    }

    public function test_a_break_is_marked_as_coming_from_the_app(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/break')
            ->assertOk()
            ->assertJsonPath('punch.source', 'mobile');
    }

    public function test_gps_travels_with_a_break(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/break', [
            'latitude' => 40.7128, 'longitude' => -74.0060,
        ])->assertOk();

        $log = AttendanceLog::where('type', 'break_start')->first();

        $this->assertEquals(40.7128, (float) $log->latitude);
        $this->assertEquals(-74.0060, (float) $log->longitude);
    }

    public function test_a_double_tap_on_break_is_refused(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');

        // Inside the cooldown of the punch just made, rather than travelling on.
        $this->postJson('/api/v1/attendance/break')
            ->assertStatus(429)
            ->assertJsonPath('error', 'duplicate_scan');
    }

    public function test_a_break_needs_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/attendance/break')->assertStatus(401);
    }

    public function test_a_finished_break_does_not_end_the_working_day(): void
    {
        // The regression this endpoint would otherwise have caused: break_end is
        // neither 'in' nor 'out', so anything reading the last punch treats a
        // returning employee as one who went home, and the next tap opens a
        // second attendance stretch.
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));
        $this->punch('break_start', '2026-08-03 13:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:30:00'));
        $this->punch('break_end', '2026-08-03 13:30:00');
        $this->travelTo(Carbon::parse('2026-08-03 15:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('is_clocked_in', true)
            ->assertJsonPath('next_action', 'out')
            ->assertJsonPath('on_break', false);

        $this->postJson('/api/v1/attendance/check')
            ->assertOk()
            ->assertJsonPath('punch.type', 'out');
    }

    // ================= today =================

    public function test_today_stays_clocked_in_through_a_break(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('break_start', '2026-08-03 13:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:20:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('is_clocked_in', true)
            ->assertJsonPath('next_action', 'out')
            ->assertJsonPath('on_break', true)
            ->assertJsonPath('next_break_action', 'end')
            ->assertJsonPath('break_started_at', '2026-08-03T13:00:00+00:00');
    }

    public function test_the_break_button_is_offered_only_on_the_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('can_break', false)
            ->assertJsonPath('on_break', false)
            ->assertJsonPath('break_started_at', null);

        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 10:00:00'));

        $this->getJson('/api/v1/attendance/today')->assertJsonPath('can_break', true);
    }

    public function test_a_break_is_not_paid_time(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('break_start', '2026-08-03 12:00:00');
        $this->punch('break_end', '2026-08-03 12:30:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        // Four hours on the clock, half an hour of it on a break.
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('worked_minutes', 210);
    }

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

    public function test_today_says_what_a_break_costs(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));

        // The server has known this since A5.7 shipped and never said — which
        // left the one screen with a break button unable to answer the only
        // question somebody has before pressing it.
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('shift.break_minutes', 30)
            ->assertJsonPath('shift.break_is_paid', false)
            ->assertJsonPath('shift.break_is_minimum', false);
    }

    public function test_today_reports_a_paid_break_as_paid(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 08:00:00'));
        $this->employee->shift->update(['break_is_paid' => true, 'break_is_minimum' => true]);

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('shift.break_is_paid', true)
            ->assertJsonPath('shift.break_is_minimum', true);
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
    }    // ================= what the handset says about itself (B2.7) =================

    /**
     * The three flags are stored against the punch, and the punch is recorded
     * either way.
     *
     * **Recorded, never enforced.** A mocked fix is a reason for somebody to
     * look at a row, not a reason to refuse a clock-in: office, remote and
     * hybrid staff punch from wherever they are, and a false positive that
     * stops somebody being paid is a worse failure than a true positive nobody
     * acted on for a day.
     */
    public function test_a_punch_carries_what_the_handset_reported(): void
    {
        $this->postJson('/api/v1/attendance/check', [
            'latitude'        => 40.7128,
            'longitude'       => -74.006,
            'location_mocked' => true,
            'device_rooted'   => true,
            'device_emulator' => false,
        ])->assertOk();

        $log = AttendanceLog::latest('id')->first();

        $this->assertTrue($log->location_mocked);
        $this->assertTrue($log->device_rooted);
        $this->assertFalse($log->device_emulator);

        // The punch happened. That is the point.
        $this->assertSame('in', $log->type);
        $this->assertTrue($log->looksTampered());
    }

    /**
     * Silence is stored as silence.
     *
     * A punch from the web portal, from the kiosk, or from an app build older
     * than this feature says nothing about the device. `null` carries that;
     * `false` would be a clean bill of health nobody issued, and an integrity
     * column that cannot tell silence from a denial is not one.
     */
    public function test_a_client_that_says_nothing_is_recorded_as_having_said_nothing(): void
    {
        $this->postJson('/api/v1/attendance/check', [
            'latitude'  => 40.7128,
            'longitude' => -74.006,
        ])->assertOk();

        $log = AttendanceLog::latest('id')->first();

        $this->assertNull($log->location_mocked);
        $this->assertNull($log->device_rooted);
        $this->assertNull($log->device_emulator);

        // And it is not swept into the register's "flagged" filter.
        $this->assertFalse($log->looksTampered());
    }

    public function test_a_clean_report_is_not_a_flag(): void
    {
        $this->postJson('/api/v1/attendance/check', [
            'location_mocked' => false,
            'device_rooted'   => false,
            'device_emulator' => false,
        ])->assertOk();

        $log = AttendanceLog::latest('id')->first();

        // Explicitly false, which is a real statement — and not a flag.
        $this->assertFalse($log->location_mocked);
        $this->assertFalse($log->looksTampered());
    }

    /**
     * A queued punch carries what was true when it was tapped.
     *
     * Per punch and not per batch: a queue can hold one fix taken with a
     * spoofer running and the next taken without it, and collapsing them to one
     * verdict for the sync would lose exactly the row worth looking at.
     */
    public function test_synced_punches_each_keep_their_own_flags(): void
    {
        // After both, so neither is dated in the future — a punch that has not
        // happened yet is refused, which is the whole reason the queue stamps
        // the moment of the tap rather than the moment of delivery.
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->postJson('/api/v1/attendance/sync', [
            'punches' => [
                [
                    'occurred_at'     => '2026-08-03 08:00:00',
                    'location_mocked' => true,
                ],
                [
                    'occurred_at'     => '2026-08-03 17:00:00',
                    'location_mocked' => false,
                ],
            ],
        ])->assertOk()->assertJsonPath('accepted', 2);

        $logs = AttendanceLog::orderBy('scanned_at')->get();

        $this->assertTrue($logs[0]->location_mocked);
        $this->assertFalse($logs[1]->location_mocked);
    }

    public function test_a_break_is_a_punch_like_any_other(): void
    {
        // travelTo before the punch, not after: the duplicate cooldown measures
        // created_at. See test_a_break_starts_when_on_the_clock.
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 13:00:00'));

        $this->postJson('/api/v1/attendance/break', [
            'location_mocked' => true,
        ])->assertOk();

        $break = AttendanceLog::where('type', 'break_start')->latest('id')->first();

        $this->assertTrue($break->location_mocked);
    }

    public function test_a_flag_that_is_not_a_boolean_is_refused(): void
    {
        // The column is a three-state: true, false, or nothing. A string would
        // cast to one of the two states silently, which is how "maybe" becomes
        // a verdict.
        $this->postJson('/api/v1/attendance/check', [
            'location_mocked' => 'probably',
        ])->assertStatus(422)->assertJsonValidationErrors('location_mocked');

        $this->assertSame(0, AttendanceLog::count());
    }
}
