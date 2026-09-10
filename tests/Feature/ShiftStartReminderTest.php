<?php

namespace Tests\Feature;

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
use App\Notifications\ShiftStartingReminder;
use App\Support\AppRoute;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The morning before anybody has arrived (B5.1).
 *
 * Almost everything here is a reason *not* to send. That is the shape of the
 * feature: a reminder to clock in is welcome once and an intrusion every other
 * time, and the expensive mistakes are all false positives — waking somebody on
 * a bank holiday, on approved leave, or on the one Saturday they are not
 * working. The single happy path is the short test at the top.
 */
class ShiftStartReminderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Shift $day;
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

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);

        $user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ])->load('user');
    }

    /** Monday 3 August 2026 — a working day under the default Sat/Sun weekend. */
    protected function at(string $time): void
    {
        $this->travelTo(Carbon::parse("2026-08-03 {$time}"));
    }

    protected function run_(): void
    {
        $this->artisan('attendance:remind-checkin')->assertSuccessful();
    }

    protected function setPolicy(array $settings): void
    {
        $this->company->update([
            'settings' => array_merge($this->company->settings ?? [], $settings),
        ]);
    }

    // ================= the one happy path =================

    public function test_somebody_rostered_is_reminded_shortly_before_their_shift(): void
    {
        Notification::fake();

        // Ten minutes is the default lead, so 08:50 is the moment the window opens.
        $this->at('08:52:00');
        $this->run_();

        Notification::assertSentTo($this->employee->user, ShiftStartingReminder::class);
    }

    // ================= the window =================

    public function test_nobody_is_reminded_before_the_window_opens(): void
    {
        Notification::fake();

        $this->at('08:30:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_nobody_is_reminded_once_the_shift_has_started(): void
    {
        Notification::fake();

        // A reminder to clock in that lands after the start is an accusation
        // rather than a reminder — and the late-arrival digest already covers it.
        $this->at('09:05:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_the_window_opens_exactly_on_the_lead(): void
    {
        Notification::fake();

        // The scheduler runs every five minutes and the shortest permitted lead
        // is five, so the run that lands exactly on the boundary is the only one
        // some configurations ever get. It has to count.
        $this->at('08:50:00');
        $this->run_();

        Notification::assertSentTo($this->employee->user, ShiftStartingReminder::class);
    }

    public function test_the_lead_is_configurable(): void
    {
        Notification::fake();

        $this->setPolicy(['checkin_reminder_before_minutes' => 45]);

        $this->at('08:30:00');
        $this->run_();

        Notification::assertSentTo($this->employee->user, ShiftStartingReminder::class);
    }

    public function test_a_lead_of_zero_switches_the_reminder_off(): void
    {
        Notification::fake();

        $this->setPolicy(['checkin_reminder_before_minutes' => 0]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    // ================= reasons not to send =================

    public function test_somebody_already_clocked_in_is_left_alone(): void
    {
        Notification::fake();

        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'office_id' => $this->office->id, 'type' => 'in',
            'scanned_at' => Carbon::parse('2026-08-03 08:40:00'),
            'work_date' => '2026-08-03', 'status' => 'ontime', 'source' => 'button',
        ]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_is_sent_once_however_often_the_scheduler_runs(): void
    {
        // Not faked: the guard reads the notification history back, so a fake
        // that never writes a row would let every run through and pass anyway.
        $this->at('08:50:00');
        $this->run_();
        $this->at('08:55:00');
        $this->run_();
        $this->at('08:59:00');
        $this->run_();

        $this->assertSame(1, $this->employee->user->notifications()->count());
    }

    public function test_a_weekend_is_skipped(): void
    {
        Notification::fake();

        // Saturday 1 August 2026. The default shift applies every day, so
        // without the calendar check every employee would be woken at the
        // weekend — the roster is the exception, the working week is the rule.
        $this->travelTo(Carbon::parse('2026-08-01 08:52:00'));
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_a_company_holiday_is_skipped(): void
    {
        Notification::fake();

        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Summer Bank Holiday',
            'date' => '2026-08-03',
        ]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_approved_leave_is_skipped(): void
    {
        Notification::fake();

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'days_per_year' => 20,
        ]);

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-03', 'days' => 1, 'status' => 'approved',
        ]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_a_pending_leave_request_is_not_a_reason_to_stay_quiet(): void
    {
        Notification::fake();

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'days_per_year' => 20,
        ]);

        // Asked for, not granted. They are expected at work until somebody says
        // otherwise, and the reminder is exactly what stops them forgetting.
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-08-03',
            'end_date' => '2026-08-03', 'days' => 1, 'status' => 'pending',
        ]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertSentTo($this->employee->user, ShiftStartingReminder::class);
    }

    public function test_a_rostered_day_off_is_skipped(): void
    {
        Notification::fake();

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-03', 'is_day_off' => true,
        ]);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    public function test_a_departed_employee_is_skipped(): void
    {
        Notification::fake();

        $this->employee->update(['status' => 'inactive']);

        $this->at('08:52:00');
        $this->run_();

        Notification::assertNothingSent();
    }

    // ================= the roster overrides the default shift =================

    public function test_the_rostered_shift_wins_over_the_default_one(): void
    {
        Notification::fake();

        $early = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Early',
            'start_time' => '06:00:00', 'end_time' => '14:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $early->id, 'date' => '2026-08-03', 'is_day_off' => false,
        ]);

        // 08:52 is now well past the start, and 05:52 is the window.
        $this->at('08:52:00');
        $this->run_();
        Notification::assertNothingSent();

        $this->at('05:52:00');
        $this->run_();
        Notification::assertSentTo($this->employee->user, ShiftStartingReminder::class);
    }

    public function test_a_shift_starting_just_after_midnight_is_reminded_the_evening_before(): void
    {
        Notification::fake();

        $small = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Small hours',
            'start_time' => '00:15:00', 'end_time' => '08:15:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        ShiftAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'shift_id' => $small->id, 'date' => '2026-08-04', 'is_day_off' => false,
        ]);

        $this->setPolicy(['checkin_reminder_before_minutes' => 30]);

        // 23:50 on the 3rd. The window belongs to tomorrow's work date, which is
        // the only reason the command looks a day ahead at all.
        $this->at('23:50:00');
        $this->run_();

        Notification::assertSentTo(
            $this->employee->user,
            ShiftStartingReminder::class,
            fn (ShiftStartingReminder $note) => $note->workDate === '2026-08-04',
        );
    }

    // ================= what is actually sent =================

    public function test_the_push_names_the_start_time_and_opens_the_clock_tab(): void
    {
        $this->at('08:52:00');

        $note = new ShiftStartingReminder('2026-08-03', Carbon::parse('2026-08-03 09:00:00'));
        $push = $note->toPush($this->employee->user);

        $this->assertSame('Your shift starts soon', $push->title);
        $this->assertStringContainsString('09:00 AM', $push->body);
        $this->assertSame(AppRoute::CLOCK, $push->data['route']);
        $this->assertSame('attendance.shift_starting', $push->data['type']);
    }

    public function test_it_never_sends_mail(): void
    {
        $note = new ShiftStartingReminder('2026-08-03', Carbon::parse('2026-08-03 09:00:00'));

        // Useful for ten minutes; misleading afterwards. Every other
        // notification here has a mail leg and this one deliberately does not.
        $this->assertNotContains('mail', $note->via($this->employee->user));
        $this->assertContains('database', $note->via($this->employee->user));
    }

    public function test_the_bell_row_carries_the_work_date_and_a_title(): void
    {
        $note = new ShiftStartingReminder('2026-08-03', Carbon::parse('2026-08-03 09:00:00'));
        $data = $note->toDatabase($this->employee->user);

        $this->assertSame('2026-08-03', $data['work_date']);
        $this->assertSame('attendance.shift_starting', $data['type']);
        $this->assertNotSame('', $data['title']);
    }

    public function test_the_reminder_follows_the_recipients_language(): void
    {
        $this->employee->user->forceFill(['locale' => 'es'])->save();

        $this->at('08:52:00');
        $this->run_();

        $row = $this->employee->user->notifications()->first();

        $this->assertSame('Tu turno empieza pronto', $row->data['title']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->at('08:52:00');

        $this->artisan('attendance:remind-checkin --dry-run')
            ->expectsOutputToContain('1 reminder(s) would be sent.')
            ->assertSuccessful();

        $this->assertSame(0, $this->employee->user->notifications()->count());
    }
}
