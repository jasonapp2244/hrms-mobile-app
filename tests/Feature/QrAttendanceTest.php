<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrToken;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\QrDisplay;
use App\Models\User;
use App\Services\QrAttendanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QR check-in (A4.21): the office screen shows a code, the employee's own
 * phone scans it, and the server records the punch.
 *
 * What has to hold, and what each group below pins:
 *  - the punch is the same punch the button makes — direction, status, office;
 *  - one code, one scan: a second scan of the same code, an expired one, and
 *    one from another company all fail and write nothing;
 *  - the policy only ever binds office staff, and binds every door they have;
 *  - the screen is reachable by its signed link and nothing else.
 */
class QrAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Office $office;
    private User $user;
    private Employee $employee;
    private QrDisplay $display;
    private QrAttendanceService $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-30 08:50:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD']);
        $this->office  = Office::create(['company_id' => $this->company->id, 'name' => 'HQ', 'is_active' => true]);

        [$this->user, $this->employee] = $this->person('ann', $this->company, $this->office);

        $this->display = QrDisplay::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id, 'name' => 'Front desk',
        ]);

        $this->qr = app(QrAttendanceService::class);
    }

    /** @return array{0: User, 1: Employee} */
    private function person(string $name, Company $company, ?Office $office, array $employee = []): array
    {
        $user = User::create([
            'name' => ucfirst($name), 'email' => "{$name}@{$company->id}.test",
            'password' => Hash::make('password'), 'company_id' => $company->id, 'is_active' => true,
        ]);
        $user->assignRole('employee');

        $record = Employee::create(array_merge([
            'company_id' => $company->id, 'office_id' => $office?->id, 'user_id' => $user->id,
            'employee_code' => strtoupper($name), 'first_name' => ucfirst($name), 'last_name' => 'Test',
            'status' => 'active',
        ], $employee));

        return [$user, $record];
    }

    private function requireQr(bool $on = true): void
    {
        $this->company->update(['settings' => ['require_qr_checkin' => $on]]);
    }

    /** The text a phone would read off the screen right now. */
    private function code(?QrDisplay $display = null): string
    {
        return $this->qr->issue($display ?? $this->display)['payload'];
    }

    private function scan(string $code, array $extra = [])
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/v1/attendance/qr', ['qr' => $code] + $extra);
    }

    // ================= the punch =================

    public function test_scanning_the_screen_checks_in_and_the_next_scan_checks_out(): void
    {
        $this->scan($this->code())
            ->assertOk()
            ->assertJsonPath('punch.type', 'in')
            ->assertJsonPath('punch.source', 'qr')
            ->assertJsonPath('punch.office', 'HQ')
            ->assertJsonPath('next_action', 'out');

        $this->travelTo(Carbon::parse('2026-09-30 17:05:00'));

        $this->scan($this->code())
            ->assertOk()
            ->assertJsonPath('punch.type', 'out');

        $this->assertSame(['in', 'out'], AttendanceLog::orderBy('id')->pluck('type')->all());
    }

    public function test_a_scanned_code_is_claimed_by_the_punch_it_made(): void
    {
        $this->scan($this->code())->assertOk();

        $token = AttendanceQrToken::sole();
        $this->assertNotNull($token->consumed_at);
        $this->assertSame($this->employee->id, $token->consumed_by_employee_id);
        $this->assertSame(AttendanceLog::sole()->id, $token->attendance_log_id);
    }

    public function test_the_punch_is_filed_against_the_office_on_the_screen(): void
    {
        $depot = Office::create(['company_id' => $this->company->id, 'name' => 'Depot', 'is_active' => true]);
        $there = QrDisplay::create(['company_id' => $this->company->id, 'office_id' => $depot->id, 'name' => 'Depot door']);

        $this->scan($this->code($there))->assertOk()->assertJsonPath('punch.office', 'Depot');

        $this->assertSame($depot->id, AttendanceLog::sole()->office_id);
    }

    // ================= one code, one scan =================

    public function test_the_same_code_cannot_be_used_twice(): void
    {
        $code = $this->code();

        $this->scan($code)->assertOk();

        [$bob] = $this->person('bob', $this->company, $this->office);
        Sanctum::actingAs($bob);

        $this->postJson('/api/v1/attendance/qr', ['qr' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error', 'qr_already_used');

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->code();

        $this->travel(QrAttendanceService::TOKEN_SECONDS + 1)->seconds();

        $this->scan($code)->assertStatus(422)->assertJsonPath('error', 'qr_expired');
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_anything_that_is_not_a_live_office_code_is_invalid(): void
    {
        foreach (['', 'hello', 'KEMP1:1', 'KEMP1:x:abc', 'KEMP1:' . $this->office->id . ':not-a-token'] as $text) {
            $this->scan($text)->assertStatus(422)->assertJsonPath('error', $text === '' ? 'validation_failed' : 'qr_invalid');
        }

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_a_code_with_the_office_number_changed_is_invalid(): void
    {
        [, , $token] = explode(':', $this->code());

        $this->scan('KEMP1:' . ($this->office->id + 99) . ':' . $token)
            ->assertStatus(422)->assertJsonPath('error', 'qr_invalid');
    }

    public function test_another_companys_code_reads_as_no_code_at_all(): void
    {
        $other  = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $office = Office::create(['company_id' => $other->id, 'name' => 'Rival HQ', 'is_active' => true]);
        $screen = QrDisplay::create(['company_id' => $other->id, 'office_id' => $office->id, 'name' => 'Rival desk']);

        $this->scan($this->code($screen))->assertStatus(422)->assertJsonPath('error', 'qr_invalid');

        $this->assertNull(AttendanceQrToken::sole()->consumed_at);
    }

    public function test_a_switched_off_screen_stops_its_codes_working(): void
    {
        $code = $this->code();
        $this->display->revoke($this->user);

        $this->scan($code)->assertStatus(422)->assertJsonPath('error', 'qr_invalid');
    }

    public function test_a_punch_refused_by_the_geofence_leaves_the_code_unclaimed(): void
    {
        $this->company->update(['settings' => ['enforce_geofence' => true]]);
        $this->office->update(['latitude' => 51.5000000, 'longitude' => -0.1200000, 'geofence_radius' => 100]);

        $this->scan($this->code(), ['latitude' => 40.7, 'longitude' => -74.0])
            ->assertStatus(422)->assertJsonPath('error', 'outside_geofence');

        $this->assertNull(AttendanceQrToken::sole()->consumed_at);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_the_cooldown_applies_to_a_scan_as_it_does_to_the_button(): void
    {
        $this->scan($this->code())->assertOk();

        $this->scan($this->code())->assertStatus(429)->assertJsonPath('error', 'duplicate_scan');
    }

    // ================= who has to scan =================

    public function test_today_tells_the_app_which_way_to_punch(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('method', 'button');

        $this->requireQr();

        // A fresh user: the one above still holds the company it loaded before
        // the policy changed, which a real request never would.
        Sanctum::actingAs($this->user->fresh());

        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('method', 'qr');
    }

    public function test_office_staff_cannot_tap_the_button_once_qr_is_required(): void
    {
        $this->requireQr();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/check')
            ->assertStatus(422)->assertJsonPath('error', 'qr_required');

        $this->postJson('/api/v1/attendance/sync', ['punches' => [['occurred_at' => now()->subMinutes(5)->toIso8601String()]]])
            ->assertOk()
            ->assertJsonPath('results.0.result', 'refused')
            ->assertJsonPath('refused', 1);

        $this->assertSame(0, AttendanceLog::count());

        $this->scan($this->code())->assertOk();
    }

    public function test_wfh_and_hybrid_staff_keep_the_button(): void
    {
        $this->requireQr();

        foreach (['wfh', 'hybrid'] as $mode) {
            [$user] = $this->person($mode, $this->company, $this->office, ['work_mode' => $mode]);
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/attendance/today')->assertJsonPath('method', 'button');
            $this->postJson('/api/v1/attendance/check')->assertOk();
        }
    }

    public function test_breaks_stay_a_button_under_the_policy(): void
    {
        $this->requireQr();
        $this->scan($this->code())->assertOk();

        $this->travel(2)->minutes();

        $this->postJson('/api/v1/attendance/break')->assertOk()->assertJsonPath('on_break', true);
    }

    public function test_the_web_portal_button_is_refused_and_not_offered(): void
    {
        $this->requireQr();

        $this->actingAs($this->user)
            ->postJson(route('employee.check'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->actingAs($this->user)
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('Check in and out with the QR code.')
            ->assertDontSee('id="check-btn"', false);
    }

    // ================= the screen =================

    public function test_the_screen_opens_from_its_signed_link_only(): void
    {
        $this->get($this->display->url())->assertOk()->assertSee('Front desk');

        $this->get('/qr-display/' . $this->display->id)->assertForbidden();
    }

    public function test_the_screen_keeps_a_code_until_it_is_used_then_shows_a_new_one(): void
    {
        $url = URL::signedRoute('qr-display.current', ['display' => $this->display->id]);

        $first = $this->getJson($url)->assertOk()->assertJsonPath('changed', true)->json();
        $this->assertStringContainsString('<svg', $first['svg']);

        $this->getJson($url, ['X-Showing' => $first['token_id']])
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('token_id', $first['token_id'])
            ->assertJsonPath('svg', null);

        // Scanned by somebody: the next poll must move on, and name them.
        AttendanceQrToken::whereKey($first['token_id'])->update(['consumed_at' => now()]);

        $next = $this->getJson($url, ['X-Showing' => $first['token_id']])->assertOk()->json();
        $this->assertTrue($next['changed']);
        $this->assertNotSame($first['token_id'], $next['token_id']);
    }

    public function test_the_screen_moves_on_before_a_code_runs_out(): void
    {
        $url   = URL::signedRoute('qr-display.current', ['display' => $this->display->id]);
        $first = $this->getJson($url)->json();

        $this->travel(QrAttendanceService::TOKEN_SECONDS - QrAttendanceService::REFRESH_MARGIN_SECONDS + 1)->seconds();

        $this->getJson($url, ['X-Showing' => $first['token_id']])->assertJsonPath('changed', true);
    }

    public function test_the_screen_greets_the_person_who_just_scanned(): void
    {
        $this->scan($this->code())->assertOk();

        $this->getJson(URL::signedRoute('qr-display.current', ['display' => $this->display->id]))
            ->assertJsonPath('last_scan.name', $this->employee->full_name)
            ->assertJsonPath('last_scan.type', 'in');
    }

    public function test_a_switched_off_screen_stops_showing_codes(): void
    {
        $url = URL::signedRoute('qr-display.current', ['display' => $this->display->id]);
        $this->display->revoke($this->user);

        $this->getJson($url)->assertStatus(410);
        $this->get($this->display->url())->assertStatus(410);
    }

    // ================= managing screens =================

    private function hr(): User
    {
        $hr = User::create([
            'name' => 'Hana', 'email' => 'hr@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $hr->assignRole('hr');

        return $hr;
    }

    public function test_hr_sets_up_and_switches_off_a_screen(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->get(route('attendance.qr-displays.index'))->assertOk()->assertSee('Front desk');

        $this->actingAs($hr)
            ->post(route('attendance.qr-displays.store'), ['office_id' => $this->office->id, 'name' => 'Back door'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $screen = QrDisplay::where('name', 'Back door')->sole();
        $this->assertSame($this->company->id, $screen->company_id);

        $this->actingAs($hr)->post(route('attendance.qr-displays.revoke', $screen))->assertRedirect();
        $this->assertTrue($screen->fresh()->isRevoked());
    }

    public function test_a_screen_cannot_be_made_for_or_taken_from_another_company(): void
    {
        $hr     = $this->hr();
        $other  = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $office = Office::create(['company_id' => $other->id, 'name' => 'Rival HQ', 'is_active' => true]);
        $theirs = QrDisplay::create(['company_id' => $other->id, 'office_id' => $office->id, 'name' => 'Theirs']);

        $this->actingAs($hr)
            ->post(route('attendance.qr-displays.store'), ['office_id' => $office->id, 'name' => 'Sneaky'])
            ->assertSessionHas('error');
        $this->assertFalse(QrDisplay::where('name', 'Sneaky')->exists());

        $this->actingAs($hr)->post(route('attendance.qr-displays.revoke', $theirs))->assertForbidden();
        $this->assertFalse($theirs->fresh()->isRevoked());
    }

    public function test_an_employee_cannot_reach_the_screens_page(): void
    {
        $this->actingAs($this->user)->get(route('attendance.qr-displays.index'))->assertForbidden();
    }

    public function test_the_policy_is_switched_on_from_the_policies_screen(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('policies.edit'))->assertOk()->assertSee('name="require_qr_checkin"', false);

        $this->actingAs($admin)->put(route('policies.update'), [
            'weekend_days' => [0, 6],
            'checkin_reminder_before_minutes' => 10,
            'checkout_reminder_after_minutes' => 30,
            'auto_close_after_minutes' => 240,
            'session_idle_timeout_minutes' => 0,
            'default_day_start' => '09:00',
            'default_day_end' => '17:00',
            'default_day_grace_minutes' => 15,
            'require_qr_checkin' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) $this->company->fresh()->policy('require_qr_checkin'));
        $this->assertTrue($this->qr->requiresQr($this->employee->fresh()));
    }
}
