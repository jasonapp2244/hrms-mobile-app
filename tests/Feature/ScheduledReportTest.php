<?php

namespace Tests\Feature;

use App\Mail\ScheduledReportMail;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ReportSubscription;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A7.12 — scheduled report email delivery.
 *
 * Two things are really under test. The calendar: a run must cover a finished
 * period and must not cover it twice, in the company's timezone rather than the
 * server's. And the delivery: something has to actually be attached, because a
 * report that arrives as an empty file is worse than one that never arrives —
 * nobody chases the second.
 */
class ScheduledReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->staff->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $this->staff->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'shift_id' => $shift->id,
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribe(array $overrides = []): ReportSubscription
    {
        return ReportSubscription::create(array_merge([
            'company_id'  => $this->company->id,
            'report_type' => 'payroll',
            'frequency'   => 'daily',
            'format'      => 'pdf',
            'recipients'  => ['finance@acme.test'],
            'is_active'   => true,
        ], $overrides));
    }

    /** Freeze the clock at a moment that is $localTime in the company's zone. */
    private function atCompanyTime(string $localTime): Carbon
    {
        $local = Carbon::parse($localTime, $this->company->tz());
        Carbon::setTestNow($local->copy()->utc());

        return $local;
    }

    // -------------------------------------------------------------------------
    // Which period a run covers
    // -------------------------------------------------------------------------

    public function test_a_daily_report_covers_yesterday(): void
    {
        $period = $this->subscribe(['frequency' => 'daily'])
            ->periodFor(Carbon::parse('2026-08-05 07:00', $this->company->tz()));

        $this->assertSame(['from' => '2026-08-04', 'to' => '2026-08-04'], $period);
    }

    public function test_a_weekly_report_covers_the_week_that_just_ended(): void
    {
        // Sent Monday 10 August; the week reported on is Mon 3 – Sun 9.
        $period = $this->subscribe(['frequency' => 'weekly'])
            ->periodFor(Carbon::parse('2026-08-10 07:00', $this->company->tz()));

        $this->assertSame(['from' => '2026-08-03', 'to' => '2026-08-09'], $period);
    }

    public function test_a_monthly_report_covers_the_month_that_just_ended(): void
    {
        $period = $this->subscribe(['frequency' => 'monthly'])
            ->periodFor(Carbon::parse('2026-09-01 07:00', $this->company->tz()));

        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], $period);
    }

    public function test_a_monthly_report_sent_on_the_first_of_march_does_not_skip_february(): void
    {
        // subMonth from 1 March lands on 1 February only if the overflow is
        // suppressed; otherwise a 31-day step walks past the short month.
        $period = $this->subscribe(['frequency' => 'monthly'])
            ->periodFor(Carbon::parse('2027-03-01 07:00', $this->company->tz()));

        $this->assertSame(['from' => '2027-02-01', 'to' => '2027-02-28'], $period);
    }

    // -------------------------------------------------------------------------
    // When a run is due
    // -------------------------------------------------------------------------

    public function test_nothing_is_due_before_the_send_hour(): void
    {
        $subscription = $this->subscribe();

        $this->assertFalse($subscription->isDue(Carbon::parse('2026-08-05 06:00', $this->company->tz())));
        $this->assertTrue($subscription->isDue(Carbon::parse('2026-08-05 07:00', $this->company->tz())));
    }

    public function test_a_daily_report_is_not_sent_twice_in_one_day(): void
    {
        $subscription = $this->subscribe();
        $morning = Carbon::parse('2026-08-05 07:00', $this->company->tz());

        $subscription->forceFill(['last_sent_at' => $morning])->save();

        $this->assertFalse($subscription->fresh()->isDue($morning->copy()->addHours(4)));
    }

    public function test_a_daily_report_sent_yesterday_is_due_again_today(): void
    {
        $subscription = $this->subscribe();

        $subscription->forceFill([
            'last_sent_at' => Carbon::parse('2026-08-04 07:00', $this->company->tz()),
        ])->save();

        $this->assertTrue(
            $subscription->fresh()->isDue(Carbon::parse('2026-08-05 07:00', $this->company->tz())),
        );
    }

    public function test_a_run_missed_at_seven_still_goes_out_later_the_same_day(): void
    {
        // A server that was down at 07:00 must not silently skip the day.
        $this->assertTrue(
            $this->subscribe()->isDue(Carbon::parse('2026-08-05 15:00', $this->company->tz())),
        );
    }

    public function test_a_weekly_report_only_goes_out_on_a_monday(): void
    {
        $subscription = $this->subscribe(['frequency' => 'weekly']);

        $this->assertFalse($subscription->isDue(Carbon::parse('2026-08-11 07:00', $this->company->tz())));
        $this->assertTrue($subscription->isDue(Carbon::parse('2026-08-10 07:00', $this->company->tz())));
    }

    public function test_a_monthly_report_only_goes_out_on_the_first(): void
    {
        $subscription = $this->subscribe(['frequency' => 'monthly']);

        $this->assertFalse($subscription->isDue(Carbon::parse('2026-08-31 07:00', $this->company->tz())));
        $this->assertTrue($subscription->isDue(Carbon::parse('2026-09-01 07:00', $this->company->tz())));
    }

    public function test_a_paused_subscription_is_never_due(): void
    {
        $this->assertFalse(
            $this->subscribe(['is_active' => false])
                ->isDue(Carbon::parse('2026-08-05 07:00', $this->company->tz())),
        );
    }

    public function test_a_subscription_with_no_recipients_is_never_due(): void
    {
        $this->assertFalse(
            $this->subscribe(['recipients' => []])
                ->isDue(Carbon::parse('2026-08-05 07:00', $this->company->tz())),
        );
    }

    // -------------------------------------------------------------------------
    // Delivery
    // -------------------------------------------------------------------------

    public function test_a_due_report_is_emailed_to_its_recipients(): void
    {
        Mail::fake();
        $this->subscribe(['recipients' => ['finance@acme.test', 'ceo@acme.test']]);
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertQueued(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            return $mail->hasTo('finance@acme.test')
                && $mail->hasTo('ceo@acme.test')
                && $mail->reportTitle === 'Payroll Hours'
                && $mail->periodFrom === '2026-08-04'
                && $mail->periodTo === '2026-08-04';
        });
    }

    public function test_the_attached_pdf_is_not_empty(): void
    {
        Mail::fake();
        $this->subscribe(['format' => 'pdf']);
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertQueued(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            return str_ends_with($mail->filename, '.pdf')
                && str_starts_with($mail->attachmentBytes(), '%PDF');
        });
    }

    public function test_the_attached_spreadsheet_is_not_empty(): void
    {
        Mail::fake();
        $this->subscribe(['format' => 'excel']);
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertQueued(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            // xlsx is a zip; "PK" is its first two bytes.
            return str_ends_with($mail->filename, '.xlsx')
                && str_starts_with($mail->attachmentBytes(), 'PK');
        });
    }

    public function test_the_report_carries_the_periods_real_figures(): void
    {
        Mail::fake();

        foreach ([['in', '09:00:00'], ['out', '19:00:00']] as [$type, $time]) {
            AttendanceLog::create([
                'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
                'office_id' => $this->office->id, 'type' => $type,
                'scanned_at' => Carbon::parse("2026-08-04 $time"), 'work_date' => '2026-08-04',
                'status' => 'ontime', 'source' => 'button',
            ]);
        }

        $this->subscribe();
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertQueued(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            $hours = collect($mail->tiles)->firstWhere('label', 'Total Hours');

            // 09:00–19:00 less the 30-minute unpaid break.
            return $hours && (float) $hours['value'] === 9.5;
        });
    }

    public function test_a_sent_report_stamps_last_sent_at(): void
    {
        Mail::fake();
        $subscription = $this->subscribe();
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();

        $this->assertNotNull($subscription->fresh()->last_sent_at);
    }

    public function test_a_second_run_the_same_day_sends_nothing_more(): void
    {
        Mail::fake();
        $this->subscribe();
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send')->assertSuccessful();
        $this->atCompanyTime('2026-08-05 11:30');
        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertQueuedCount(1);
    }

    public function test_a_dry_run_sends_nothing_and_stamps_nothing(): void
    {
        Mail::fake();
        $subscription = $this->subscribe();
        $this->atCompanyTime('2026-08-05 07:30');

        $this->artisan('reports:send --dry-run')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($subscription->fresh()->last_sent_at);
    }

    public function test_another_companys_subscription_is_untouched_by_the_company_filter(): void
    {
        Mail::fake();
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = $this->subscribe(['company_id' => $other->id]);
        $mine = $this->subscribe();

        $this->atCompanyTime('2026-08-05 07:30');
        $this->artisan('reports:send --company=' . $this->company->id)->assertSuccessful();

        Mail::assertQueuedCount(1);
        $this->assertNotNull($mine->fresh()->last_sent_at);
        $this->assertNull($theirs->fresh()->last_sent_at);
    }

    // -------------------------------------------------------------------------
    // Managing the schedules
    // -------------------------------------------------------------------------

    public function test_hr_can_create_a_schedule(): void
    {
        $this->actingAs($this->hr)->post(route('report-subscriptions.store'), [
            'report_type' => 'leave',
            'frequency'   => 'monthly',
            'format'      => 'excel',
            'recipients'  => 'hr@acme.test, finance@acme.test',
            'is_active'   => 1,
        ])->assertRedirect();

        $subscription = ReportSubscription::firstOrFail();

        $this->assertSame('leave', $subscription->report_type);
        $this->assertSame(['hr@acme.test', 'finance@acme.test'], $subscription->recipients);
        $this->assertSame($this->hr->id, $subscription->created_by_user_id);
    }

    public function test_recipients_can_be_separated_however_they_were_pasted(): void
    {
        $this->actingAs($this->hr)->post(route('report-subscriptions.store'), [
            'report_type' => 'late',
            'frequency'   => 'daily',
            'format'      => 'pdf',
            'recipients'  => "a@acme.test; b@acme.test\nc@acme.test  a@acme.test",
        ])->assertRedirect();

        // Deduplicated too — the same address twice is one email, not two.
        $this->assertSame(
            ['a@acme.test', 'b@acme.test', 'c@acme.test'],
            ReportSubscription::firstOrFail()->recipients,
        );
    }

    public function test_a_bad_address_is_named_rather_than_rejected_wholesale(): void
    {
        $this->actingAs($this->hr)->post(route('report-subscriptions.store'), [
            'report_type' => 'late',
            'frequency'   => 'daily',
            'format'      => 'pdf',
            'recipients'  => 'good@acme.test, not-an-address',
        ])->assertSessionHasErrors('recipients');

        $this->assertStringContainsString(
            'not-an-address',
            session('errors')->first('recipients'),
        );
    }

    public function test_an_unknown_report_cannot_be_subscribed_to(): void
    {
        // The command calls this string as a method on ReportService.
        $this->actingAs($this->hr)->post(route('report-subscriptions.store'), [
            'report_type' => 'employeeStats',
            'frequency'   => 'daily',
            'format'      => 'pdf',
            'recipients'  => 'a@acme.test',
        ])->assertSessionHasErrors('report_type');
    }

    public function test_a_subscription_cannot_be_pointed_at_another_companys_office(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);

        $this->actingAs($this->hr)->post(route('report-subscriptions.store'), [
            'report_type' => 'late',
            'frequency'   => 'daily',
            'format'      => 'pdf',
            'office_id'   => $theirOffice->id,
            'recipients'  => 'a@acme.test',
        ])->assertSessionHasErrors('office_id');
    }

    public function test_send_now_delivers_without_consuming_the_schedule(): void
    {
        Mail::fake();
        $subscription = $this->subscribe();
        $this->atCompanyTime('2026-08-05 15:00');

        $this->actingAs($this->hr)
            ->post(route('report-subscriptions.send', $subscription))
            ->assertRedirect();

        Mail::assertQueuedCount(1);

        // A test send is not the day's delivery; the real one must still happen.
        $this->assertNull($subscription->fresh()->last_sent_at);
    }

    public function test_send_now_works_even_when_the_schedule_is_paused(): void
    {
        Mail::fake();
        $subscription = $this->subscribe(['is_active' => false]);
        $this->atCompanyTime('2026-08-05 15:00');

        $this->actingAs($this->hr)
            ->post(route('report-subscriptions.send', $subscription))
            ->assertRedirect();

        Mail::assertQueuedCount(1);
    }

    public function test_an_employee_cannot_see_or_create_schedules(): void
    {
        $this->actingAs($this->staff)->get(route('report-subscriptions.index'))->assertForbidden();
        $this->actingAs($this->staff)->post(route('report-subscriptions.store'), [
            'report_type' => 'payroll', 'frequency' => 'daily',
            'format' => 'pdf', 'recipients' => 'me@acme.test',
        ])->assertForbidden();
    }

    public function test_a_schedule_belonging_to_another_company_cannot_be_edited(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = $this->subscribe(['company_id' => $other->id]);

        $this->actingAs($this->hr)
            ->delete(route('report-subscriptions.destroy', $theirs))
            ->assertForbidden();

        $this->assertDatabaseHas('report_subscriptions', ['id' => $theirs->id]);
    }

    public function test_hr_can_open_the_schedule_screen(): void
    {
        $this->subscribe();

        $this->actingAs($this->hr)
            ->get(route('report-subscriptions.index'))
            ->assertOk()
            ->assertSee('Scheduled Reports')
            ->assertSee('finance@acme.test');
    }
}
