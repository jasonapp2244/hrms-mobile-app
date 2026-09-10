<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrashReport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The administrator's view of app crashes (B6.5).
 *
 * Gated with the activity log rather than with the employee screens: a stack
 * trace describes the system's internals, and the audience for it is whoever
 * owns the install.
 */
class CrashReportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
    }

    protected function account(string $email, string $role): User
    {
        $user = User::create([
            'name'       => ucfirst($role),
            'email'      => $email,
            'password'   => Hash::make('password'),
            'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function crash(array $overrides = []): CrashReport
    {
        $stack = $overrides['stack'] ?? '#0 PunchScreen.build (punch_screen.dart:88:14)';
        $exception = $overrides['exception'] ?? '_TypeError';

        return CrashReport::create(array_merge([
            'company_id'  => $this->company->id,
            'exception'   => $exception,
            'message'     => 'Null is not a String',
            'stack'       => $stack,
            'platform'    => 'android',
            'app_version' => '1.0.0',
            'fingerprint' => CrashReport::fingerprintFor($exception, $stack),
            'occurred_at' => now()->subHour(),
        ], $overrides));
    }

    public function test_an_administrator_sees_one_row_per_bug(): void
    {
        // Three handsets, one bug. A screen that listed three would be a list
        // of incidents rather than of things to fix.
        $this->crash(['app_version' => '1.0.0']);
        $this->crash(['app_version' => '1.0.0']);
        $this->crash(['app_version' => '1.1.0']);
        $this->crash([
            'exception' => 'StateError',
            'stack'     => '#0 Session.restore (session.dart:120:5)',
        ]);

        $this->actingAs($this->account('admin@acme.test', 'admin'))
            ->get(route('crashes.index'))
            ->assertOk()
            ->assertSee('_TypeError')
            ->assertSee('StateError')
            // The count for the group, not four separate rows.
            ->assertSee('>3<', false);
    }

    public function test_a_crash_from_before_sign_in_is_not_hidden(): void
    {
        // No company on the row, because nobody was identified. Those are the
        // reports worth reading — the app failing to open is its worst failure.
        $this->crash(['company_id' => null, 'exception' => 'MissingPluginException']);

        $this->actingAs($this->account('admin2@acme.test', 'admin'))
            ->get(route('crashes.index'))
            ->assertOk()
            ->assertSee('MissingPluginException');
    }

    public function test_opening_one_shows_its_stack(): void
    {
        $crash = $this->crash();

        $this->actingAs($this->account('admin3@acme.test', 'admin'))
            ->get(route('crashes.index', ['fingerprint' => $crash->fingerprint]))
            ->assertOk()
            ->assertSee('punch_screen.dart:88:14')
            ->assertSee('Before sign-in');
    }

    public function test_hr_is_refused(): void
    {
        // manage-settings is admin-only, the same gate the activity log keeps.
        $this->actingAs($this->account('hr@acme.test', 'hr'))
            ->get(route('crashes.index'))
            ->assertForbidden();
    }

    public function test_an_employee_is_refused(): void
    {
        $this->actingAs($this->account('emp@acme.test', 'employee'))
            ->get(route('crashes.index'))
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login(): void
    {
        $this->get(route('crashes.index'))->assertRedirect(route('login'));
    }

    public function test_one_crash_can_be_cleared(): void
    {
        // Unlike the trails it sits beside, a crash report is diagnosis and not
        // evidence: once a bug is fixed its reports are noise, and a screen
        // nobody can tidy is a screen nobody reads.
        $crash = $this->crash();
        $this->crash(['exception' => 'StateError', 'stack' => '#0 Session.restore (session.dart:120:5)']);

        $this->actingAs($this->account('admin4@acme.test', 'admin'))
            ->delete(route('crashes.destroy'), ['fingerprint' => $crash->fingerprint])
            ->assertRedirect(route('crashes.index'));

        $this->assertSame(1, CrashReport::count());
        $this->assertSame('StateError', CrashReport::sole()->exception);
    }

    public function test_old_reports_can_be_cleared_by_age(): void
    {
        $this->crash()->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();
        $this->crash(['exception' => 'StateError', 'stack' => '#0 Session.restore (session.dart:1:1)']);

        $this->actingAs($this->account('admin5@acme.test', 'admin'))
            ->delete(route('crashes.destroy'), ['days' => 90])
            ->assertRedirect(route('crashes.index'));

        $this->assertSame(1, CrashReport::count());
    }

    public function test_a_delete_that_names_nothing_clears_nothing(): void
    {
        // "Everything" is not a thing a stray form post should be able to do.
        $this->crash();

        $this->actingAs($this->account('admin6@acme.test', 'admin'))
            ->delete(route('crashes.destroy'), [])
            ->assertRedirect();

        $this->assertSame(1, CrashReport::count());
    }
}
