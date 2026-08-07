<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 8 — the security trail (A1.8), the idle timeout (A1.9), the working
 * week editor (A2.8) and geofence enforcement (A4.16).
 *
 * Grouped because they are one screen and one decision: what the company's
 * policy is, and what the system does about it. The thing worth testing hardest
 * is that each is *off* until somebody turns it on — a geofence or a timeout
 * that arrives switched on breaks a working installation on upgrade.
 */
class SecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $admin;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        // Times Square, give or take.
        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
            'latitude' => 40.7580, 'longitude' => -73.9855, 'geofence_radius' => 100,
        ]);

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
            'status' => 'active', 'shift_id' => $shift->id, 'work_mode' => 'office',
        ]);

        $this->admin = User::create([
            'name' => 'Ada Root', 'email' => 'ada@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->admin->assignRole('admin');

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

    private function setPolicy(array $values): void
    {
        $this->company->update(['settings' => array_merge($this->company->settings ?? [], $values)]);
        $this->company->refresh();
    }

    // -------------------------------------------------------------------------
    // A1.8 — the security trail
    // -------------------------------------------------------------------------

    public function test_a_successful_sign_in_is_recorded(): void
    {
        $this->post('/login', [
            'email' => 'hana@acme.test', 'password' => 'password',
        ]);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN)->firstOrFail();

        $this->assertSame($this->hr->id, $entry->user_id);
        $this->assertSame($this->company->id, $entry->company_id);
    }

    public function test_a_failed_sign_in_records_the_address_that_was_tried(): void
    {
        $this->post('/login', [
            'email' => 'hana@acme.test', 'password' => 'wrong',
        ]);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->firstOrFail();

        $this->assertSame('hana@acme.test', $entry->actor_label);
        $this->assertStringContainsString('Wrong password', $entry->description);
    }

    public function test_an_attempt_on_an_address_nobody_holds_is_told_apart_from_a_wrong_password(): void
    {
        // Different things to whoever is reading: one is somebody guessing at a
        // person, the other is somebody guessing at the door.
        $this->post('/login', [
            'email' => 'nobody@acme.test', 'password' => 'whatever',
        ]);

        $entry = ActivityLog::where('event', ActivityLog::LOGIN_FAILED)->firstOrFail();

        $this->assertNull($entry->user_id);
        $this->assertStringContainsString('No account matches', $entry->description);
    }

    public function test_signing_out_is_recorded(): void
    {
        $this->actingAs($this->hr)->post(route('logout'));

        $this->assertDatabaseHas('activity_logs', [
            'event' => ActivityLog::LOGOUT, 'user_id' => $this->hr->id,
        ]);
    }

    public function test_a_log_entry_cannot_be_edited(): void
    {
        $entry = ActivityLog::record(ActivityLog::LOGIN, 'test', $this->hr);

        $this->expectException(RuntimeException::class);
        $entry->update(['description' => 'something else']);
    }

    public function test_a_log_entry_cannot_be_deleted(): void
    {
        $entry = ActivityLog::record(ActivityLog::LOGIN, 'test', $this->hr);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_an_admin_can_read_the_trail_and_hr_cannot(): void
    {
        ActivityLog::record(ActivityLog::LOGIN, 'Signed in via web', $this->hr);

        $this->actingAs($this->admin)->get(route('activity.index'))
            ->assertOk()->assertSee('Hana Ruiz');

        $this->actingAs($this->hr)->get(route('activity.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('activity.index'))->assertForbidden();
    }

    public function test_the_trail_can_be_filtered_by_event(): void
    {
        ActivityLog::record(ActivityLog::LOGIN, 'in', $this->hr);
        ActivityLog::record(ActivityLog::SETTINGS_CHANGED, 'a policy moved', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('activity.index', ['event' => ActivityLog::SETTINGS_CHANGED]))
            ->assertOk()
            ->assertSee('a policy moved')
            ->assertDontSee('Signed in via');
    }

    public function test_another_companys_entries_are_not_shown(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirUser = User::create([
            'name' => 'Zed Other', 'email' => 'zed@other.test',
            'password' => Hash::make('password'), 'company_id' => $other->id,
        ]);

        ActivityLog::record(ActivityLog::LOGIN, 'in', $theirUser);

        $this->actingAs($this->admin)->get(route('activity.index'))
            ->assertOk()->assertDontSee('Zed Other');
    }

    // -------------------------------------------------------------------------
    // A1.9 — the idle timeout
    // -------------------------------------------------------------------------

    public function test_nobody_is_timed_out_while_the_policy_is_off(): void
    {
        // The default. An upgrade must not start throwing people out of a system
        // that never did that before.
        $this->assertSame(0, (int) $this->company->policy('session_idle_timeout_minutes'));

        $this->actingAs($this->hr)
            ->withSession([EnforceIdleTimeout::KEY => now()->subDays(2)->toDateTimeString()])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_an_idle_session_past_the_limit_is_signed_out(): void
    {
        $this->setPolicy(['session_idle_timeout_minutes' => 15]);

        $this->actingAs($this->hr)
            ->withSession([EnforceIdleTimeout::KEY => now()->subMinutes(20)->toDateTimeString()])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_session_inside_the_limit_carries_on(): void
    {
        $this->setPolicy(['session_idle_timeout_minutes' => 15]);

        $this->actingAs($this->hr)
            ->withSession([EnforceIdleTimeout::KEY => now()->subMinutes(5)->toDateTimeString()])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_activity_resets_the_clock(): void
    {
        // Idle, not absolute — a long piece of work is never interrupted.
        $this->setPolicy(['session_idle_timeout_minutes' => 15]);

        Carbon::setTestNow('2026-08-07 10:00:00');

        $this->actingAs($this->hr)
            ->withSession([EnforceIdleTimeout::KEY => now()->subMinutes(10)->toDateTimeString()])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas(EnforceIdleTimeout::KEY, now()->toDateTimeString());
    }

    public function test_a_timeout_is_recorded_apart_from_a_deliberate_sign_out(): void
    {
        $this->setPolicy(['session_idle_timeout_minutes' => 15]);

        $this->actingAs($this->hr)
            ->withSession([EnforceIdleTimeout::KEY => now()->subMinutes(20)->toDateTimeString()])
            ->get(route('dashboard'));

        $this->assertDatabaseHas('activity_logs', [
            'event' => ActivityLog::SESSION_EXPIRED, 'user_id' => $this->hr->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // A2.8 — the working week
    // -------------------------------------------------------------------------

    public function test_an_admin_can_change_the_weekend(): void
    {
        $this->actingAs($this->admin)->put(route('policies.update'), [
            'weekend_days' => [5, 6],   // Friday and Saturday
            'checkout_reminder_after_minutes' => 30,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
        ])->assertRedirect();

        $this->assertSame([5, 6], app(LeaveService::class)->weekendDays($this->company->fresh()));
    }

    public function test_a_company_that_works_every_day_can_say_so(): void
    {
        // Ticking nothing has to mean "no weekend", not "fall back to Sat/Sun" —
        // otherwise a seven-day operation cannot be expressed at all.
        $this->actingAs($this->admin)->put(route('policies.update'), [
            'checkout_reminder_after_minutes' => 30,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
        ])->assertRedirect();

        $this->assertSame([], app(LeaveService::class)->weekendDays($this->company->fresh()));
    }

    public function test_a_week_with_no_working_days_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('policies.update'), [
            'weekend_days' => [0, 1, 2, 3, 4, 5, 6],
            'checkout_reminder_after_minutes' => 30,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
        ])->assertRedirect()->assertSessionHas('error');

        // Unchanged — leave still costs something.
        $this->assertSame([0, 6], app(LeaveService::class)->weekendDays($this->company->fresh()));
    }

    public function test_the_new_weekend_changes_what_leave_costs(): void
    {
        $leave = app(LeaveService::class);

        // Fri 7 to Mon 10 August 2026 under a Sat/Sun weekend: two days.
        $this->assertSame(2.0, $leave->chargeableDays($this->company, '2026-08-07', '2026-08-10'));

        $this->setPolicy(['weekend_days' => [5, 6]]);

        // Under a Fri/Sat weekend the same booking costs Sunday and Monday.
        $this->assertSame(2.0, $leave->chargeableDays($this->company->fresh(), '2026-08-07', '2026-08-10'));

        // And a Friday alone now costs nothing.
        $this->assertSame(0.0, $leave->chargeableDays($this->company->fresh(), '2026-08-07', '2026-08-07'));
    }

    public function test_hr_cannot_change_company_policy(): void
    {
        $this->actingAs($this->hr)->get(route('policies.edit'))->assertForbidden();
        $this->actingAs($this->hr)->put(route('policies.update'), [
            'checkout_reminder_after_minutes' => 5,
            'auto_close_after_minutes' => 5,
            'session_idle_timeout_minutes' => 5,
        ])->assertForbidden();
    }

    public function test_changing_policy_is_written_to_the_trail(): void
    {
        $this->actingAs($this->admin)->put(route('policies.update'), [
            'weekend_days' => [0, 6],
            'checkout_reminder_after_minutes' => 45,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'event' => ActivityLog::SETTINGS_CHANGED, 'user_id' => $this->admin->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // A4.16 — geofence enforcement
    // -------------------------------------------------------------------------

    private function punch(?float $lat, ?float $lng)
    {
        return $this->actingAs($this->staff)->postJson(route('employee.check'), array_filter([
            'latitude' => $lat, 'longitude' => $lng,
        ], fn ($v) => $v !== null));
    }

    public function test_a_punch_from_anywhere_is_accepted_while_enforcement_is_off(): void
    {
        // The product's premise. Off by default, and staying off is the case
        // that must not regress.
        $this->assertFalse((bool) $this->company->policy('enforce_geofence'));

        $this->punch(51.5074, -0.1278)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_a_punch_outside_the_radius_is_refused_when_enforcement_is_on(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);

        $this->punch(51.5074, -0.1278)   // London, from a New York office
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_a_punch_inside_the_radius_is_accepted(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);

        // ~30m from the office coordinates.
        $this->punch(40.75827, -73.9855)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_a_punch_with_no_location_is_never_blocked(): void
    {
        // A phone indoors often cannot get a fix. Refusing these would make the
        // feature a lockout rather than a control.
        $this->setPolicy(['enforce_geofence' => true]);

        $this->punch(null, null)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_remote_staff_are_never_blocked(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);
        $this->employee->update(['work_mode' => 'wfh']);

        $this->punch(51.5074, -0.1278)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_hybrid_staff_are_never_blocked(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);
        $this->employee->update(['work_mode' => 'hybrid']);

        $this->punch(51.5074, -0.1278)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_an_office_with_no_coordinates_fences_nobody(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);
        $this->office->update(['latitude' => null, 'longitude' => null]);

        $this->punch(51.5074, -0.1278)->assertOk()->assertJson(['ok' => true]);
    }

    public function test_the_refusal_says_how_far_away_the_person_is(): void
    {
        $this->setPolicy(['enforce_geofence' => true]);

        $response = $this->punch(51.5074, -0.1278);

        // Without a distance the message is just "no", which nobody can act on.
        $this->assertMatchesRegularExpression(
            '/\d+(\.\d+)?km|\d+m/',
            $response->json('message'),
        );
    }
}
