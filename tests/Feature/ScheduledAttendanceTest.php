<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceScore;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Notifications\MissingCheckoutReminder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The end of the day, when nobody is watching.
 *
 * Both commands are safe to run repeatedly — the scheduler runs them every
 * quarter hour and every hour — so most of what matters here is what happens
 * on the second run, not the first.
 */
class ScheduledAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Shift $day;
    protected Shift $night;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ']);

        $this->day = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);

        $this->employee = $this->staff('Ann', 'Lee', 'E1', $department);
    }

    protected function staff(string $first, string $last, string $code, Department $department): Employee
    {
        $user = User::create([
            'name' => "{$first} {$last}", 'email' => strtolower($first) . '@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        $employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $first, 'last_name' => $last,
            'status' => 'active',
        ]);

        return $employee->load('user');
    }

    protected function punch(string $type, string $at, ?Employee $employee = null): AttendanceLog
    {
        $moment = Carbon::parse($at);
        $employee ??= $this->employee;

        return AttendanceLog::create([
            'employee_id' => $employee->id, 'office_id' => $this->office->id,
            'type' => $type, 'scanned_at' => $moment, 'work_date' => $moment->toDateString(),
            'status' => 'ontime', 'source' => 'button',
        ]);
    }

    // ================= the reminder =================

    public function test_somebody_still_clocked_in_after_their_shift_is_reminded(): void
    {
        Notification::fake();

        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 17:45:00'));

        $this->artisan('attendance:remind-checkout')->assertSuccessful();

        Notification::assertSentTo($this->employee->user, MissingCheckoutReminder::class);
    }

    public function test_nobody_is_reminded_before_the_grace_period(): void
    {
        Notification::fake();

        $this->punch('in', '2026-08-03 09:00:00');
        // Ten minutes past a thirty-minute grace: still finishing up.
        $this->travelTo(Carbon::parse('2026-08-03 17:10:00'));

        $this->artisan('attendance:remind-checkout')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_somebody_who_clocked_out_is_left_alone(): void
    {
        Notification::fake();

        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:02:00');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->artisan('attendance:remind-checkout')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_day_that_reopened_is_still_open(): void
    {
        Notification::fake();

        // In, out for lunch, back in and never out again. Only the last punch
        // decides whether the day is open.
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 12:00:00');
        $this->punch('in', '2026-08-03 13:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->artisan('attendance:remind-checkout')->assertSuccessful();

        Notification::assertSentTo($this->employee->user, MissingCheckoutReminder::class);
    }

    public function test_the_reminder_is_sent_once_however_often_the_scheduler_runs(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 17:45:00'));

        $this->artisan('attendance:remind-checkout');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));
        $this->artisan('attendance:remind-checkout');
        $this->travelTo(Carbon::parse('2026-08-03 18:15:00'));
        $this->artisan('attendance:remind-checkout');

        // Repeating every quarter hour until somebody acts is how people learn
        // to mute an app.
        $this->assertSame(1, $this->employee->user->notifications()->count());
    }

    public function test_a_night_worker_is_reminded_against_the_shift_that_started_yesterday(): void
    {
        Notification::fake();

        $this->employee->update(['shift_id' => $this->night->id]);
        $this->punch('in', '2026-08-03 22:00:00');

        // 06:45 the next morning: the shift ended at 06:00 that day, not at
        // 06:00 on the day it started.
        $this->travelTo(Carbon::parse('2026-08-04 06:45:00'));

        $this->artisan('attendance:remind-checkout')->assertSuccessful();

        Notification::assertSentTo($this->employee->user, MissingCheckoutReminder::class);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        Notification::fake();

        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 18:00:00'));

        $this->artisan('attendance:remind-checkout --dry-run')
            ->expectsOutputToContain('would be sent')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    // ================= the close =================

    public function test_an_open_day_is_closed_at_the_scheduled_shift_end(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day')->assertSuccessful();

        $out = AttendanceLog::where('type', 'out')->first();

        // The scheduled end, not the moment the job happened to run: stamping
        // "now" would credit every hour since, so somebody who forgot on Friday
        // would show as having worked the weekend.
        $this->assertSame('2026-08-03 17:00:00', $out->scanned_at->format('Y-m-d H:i:s'));
        $this->assertSame('auto', $out->source);
        $this->assertStringContainsString('Automatically closed', $out->notes);
    }

    public function test_the_closed_day_reports_the_hours_that_were_scheduled(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day');

        $logs = AttendanceLog::orderBy('scanned_at')->get();

        $this->assertSame(480, app(\App\Services\AttendanceService::class)->workedMinutes($logs));
    }

    public function test_a_day_is_not_closed_during_the_grace_period(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        // Two hours over, against a four-hour grace. Overtime is normal, and
        // closing a day somebody is still working would understate their hours.
        $this->travelTo(Carbon::parse('2026-08-03 19:00:00'));

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame(0, AttendanceLog::where('type', 'out')->count());
    }

    public function test_closing_twice_does_not_write_two_clock_outs(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day');
        $this->artisan('attendance:close-day');

        // The scheduler runs this hourly; the second pass must find nothing
        // open, because the first one closed it.
        $this->assertSame(1, AttendanceLog::where('type', 'out')->count());
    }

    public function test_a_completed_day_is_left_alone(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:30:00');
        $this->travelTo(Carbon::parse('2026-08-03 23:00:00'));

        $this->artisan('attendance:close-day');

        $this->assertSame(1, AttendanceLog::where('type', 'out')->count());
        $this->assertSame('button', AttendanceLog::where('type', 'out')->first()->source);
    }

    public function test_a_night_shift_is_closed_the_following_morning(): void
    {
        $this->employee->update(['shift_id' => $this->night->id]);
        $this->punch('in', '2026-08-03 22:00:00');

        $this->travelTo(Carbon::parse('2026-08-04 11:00:00'));
        $this->artisan('attendance:close-day')->assertSuccessful();

        $out = AttendanceLog::where('type', 'out')->first();

        // 06:00 on the 4th — the shift's own end, a day after it started.
        $this->assertSame('2026-08-04 06:00:00', $out->scanned_at->format('Y-m-d H:i:s'));
        // Filed against the day the stretch of work began, like its clock-in.
        $this->assertSame('2026-08-03', $out->work_date->toDateString());
    }

    public function test_somebody_rostered_off_is_not_closed(): void
    {
        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-03', 'is_day_off' => true, 'published_at' => now(),
        ]);

        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-04 09:00:00'));

        $this->artisan('attendance:close-day')->assertSuccessful();

        // No rostered shift means no scheduled end to close at. Fabricating one
        // would be guessing at hours nobody planned.
        $this->assertSame(0, AttendanceLog::where('type', 'out')->count());
    }

    public function test_a_clock_in_after_the_shift_ended_is_not_given_an_earlier_clock_out(): void
    {
        // Turned up at 19:00 for a shift that ended at 17:00.
        $this->punch('in', '2026-08-03 19:00:00');
        $this->travelTo(Carbon::parse('2026-08-04 09:00:00'));

        $this->artisan('attendance:close-day')->assertSuccessful();

        // An "out" before the "in" would be worse than leaving it open.
        $this->assertSame(0, AttendanceLog::where('type', 'out')->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day --dry-run')
            ->expectsOutputToContain('would be closed')
            ->assertSuccessful();

        $this->assertSame(0, AttendanceLog::where('type', 'out')->count());
    }

    public function test_the_close_refreshes_the_monthly_score(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 23:30:00'));

        $this->artisan('attendance:close-day')->assertSuccessful();

        $score = AttendanceScore::where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($score, 'The nightly close should leave a score behind.');
        $this->assertSame(1, $score->present_days);
        // A score with no company is invisible to every company-scoped report,
        // and on a database that requires it the insert fails outright — which
        // would take the whole nightly job down on the first of the month.
        $this->assertSame($this->company->id, $score->company_id);
    }

    public function test_a_score_created_without_a_company_still_gets_one(): void
    {
        $score = AttendanceScore::create([
            'employee_id' => $this->employee->id,
            'period' => '2026-08', 'period_type' => 'monthly',
        ]);

        $this->assertSame($this->company->id, $score->company_id);
    }

    public function test_one_company_can_be_closed_without_touching_another(): void
    {
        $rival = Company::create(['name' => 'Rival', 'timezone' => 'UTC']);
        $rivalOffice = Office::create(['company_id' => $rival->id, 'name' => 'Their HQ']);
        $rivalDept = Department::create([
            'company_id' => $rival->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);
        $theirs = Employee::create([
            'company_id' => $rival->id, 'department_id' => $rivalDept->id,
            'office_id' => $rivalOffice->id, 'employee_code' => 'R1',
            'first_name' => 'Bob', 'last_name' => 'Ray', 'status' => 'active',
        ]);

        AttendanceLog::create([
            'employee_id' => $theirs->id, 'office_id' => $rivalOffice->id,
            'type' => 'in', 'scanned_at' => '2026-08-03 09:00:00',
            'work_date' => '2026-08-03', 'status' => 'ontime', 'source' => 'button',
        ]);
        $this->punch('in', '2026-08-03 09:00:00');

        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day --company=' . $this->company->id)->assertSuccessful();

        $this->assertSame(1, AttendanceLog::where('employee_id', $this->employee->id)->where('type', 'out')->count());
        $this->assertSame(0, AttendanceLog::where('employee_id', $theirs->id)->where('type', 'out')->count());
    }

    public function test_the_auto_closed_punch_carries_its_company(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->travelTo(Carbon::parse('2026-08-03 21:30:00'));

        $this->artisan('attendance:close-day');

        // A row with no company is invisible to every company-scoped report.
        $this->assertSame(
            $this->company->id,
            AttendanceLog::where('type', 'out')->first()->company_id,
        );
    }

    // ================= the schedule itself =================

    public function test_both_commands_are_scheduled(): void
    {
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter();

        foreach (['attendance:remind-checkout', 'attendance:close-day'] as $command) {
            $this->assertTrue(
                $scheduled->contains(fn ($c) => str_contains($c, $command)),
                "{$command} is not scheduled, so nothing would ever run it.",
            );
        }
    }
}
