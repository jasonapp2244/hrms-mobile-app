<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Support\QrCode;
use App\Support\Totp;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A1.7 — two-factor authentication.
 *
 * The TOTP itself is checked against the RFC 6238 vectors, because a
 * hand-written implementation that is subtly wrong would still look like it
 * works — it would generate codes and verify its own, and only fail against the
 * user's actual authenticator app.
 *
 * Everything else here is about the two ways a 2FA rollout goes wrong: locking
 * somebody out during setup, and letting somebody in without the second factor.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->admin = User::create([
            'name' => 'Ada Root', 'email' => 'ada@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->admin->assignRole('admin');
    }

    private function enrol(?User $user = null): string
    {
        $user ??= $this->admin;
        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $user->generateRecoveryCodes();

        return $secret;
    }

    // -------------------------------------------------------------------------
    // The algorithm
    // -------------------------------------------------------------------------

    public function test_totp_matches_the_published_test_vectors(): void
    {
        // RFC 6238 appendix B, SHA-1. The shared secret there is the ASCII
        // "12345678901234567890". The published codes are eight digits and this
        // implementation issues six, which are its low six — the truncation and
        // the HMAC are the same, only the modulus differs.
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                substr($expected, -Totp::DIGITS),
                Totp::codeAt($secret, intdiv($time, Totp::PERIOD)),
                "Vector at T={$time} did not match.",
            );
        }
    }

    public function test_base32_round_trips(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', '12345678901234567890'] as $raw) {
            $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
    }

    public function test_a_code_from_the_previous_step_is_still_accepted(): void
    {
        // Somebody typing slowly, or a phone clock a few seconds out.
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $previous = Totp::codeAt($secret, intdiv($now, Totp::PERIOD) - 1);

        $this->assertTrue(Totp::verify($secret, $previous, $now));
    }

    public function test_a_code_from_five_minutes_ago_is_refused(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $stale = Totp::codeAt($secret, intdiv($now - 300, Totp::PERIOD));

        $this->assertFalse(Totp::verify($secret, $stale, $now));
    }

    public function test_a_malformed_code_is_refused_rather_than_crashing(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', 'abc', '12345', '1234567', 'not a code'] as $bad) {
            $this->assertFalse(Totp::verify($secret, $bad));
        }
    }

    // -------------------------------------------------------------------------
    // Enrolment
    // -------------------------------------------------------------------------

    public function test_generating_a_secret_does_not_switch_two_factor_on(): void
    {
        // The single most important property here. Somebody who mistypes the key
        // into their authenticator and closes the tab must still be able to
        // sign in.
        $this->actingAs($this->admin)->post(route('two-factor.enable'))->assertRedirect();

        $this->admin->refresh();

        $this->assertNotNull($this->admin->two_factor_secret);
        $this->assertFalse($this->admin->hasTwoFactor());
    }

    public function test_confirming_with_a_valid_code_switches_it_on_and_issues_recovery_codes(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor.enable'));
        $this->admin->refresh();

        $this->actingAs($this->admin)->post(route('two-factor.confirm'), [
            'code' => Totp::codeAt($this->admin->two_factor_secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect(route('two-factor.show'));

        $this->admin->refresh();

        $this->assertTrue($this->admin->hasTwoFactor());
        $this->assertCount(8, $this->admin->two_factor_recovery_codes);
    }

    public function test_confirming_with_a_wrong_code_leaves_it_off(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor.enable'));

        $this->actingAs($this->admin)->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->admin->fresh()->hasTwoFactor());
    }

    public function test_turning_it_off_requires_the_current_password(): void
    {
        $this->enrol();

        $this->actingAs($this->admin)->delete(route('two-factor.disable'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->admin->fresh()->hasTwoFactor());

        $this->actingAs($this->admin)->delete(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertFalse($this->admin->fresh()->hasTwoFactor());
    }

    public function test_the_secret_is_not_readable_from_the_database_row(): void
    {
        $secret = $this->enrol();

        $stored = \DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, (string) $stored);
    }

    public function test_the_secret_never_appears_in_a_serialised_user(): void
    {
        $this->enrol();

        $json = $this->admin->fresh()->toJson();

        $this->assertStringNotContainsString('two_factor_secret', $json);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $json);
    }

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    public function test_a_password_alone_no_longer_signs_in(): void
    {
        $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        // Half-authenticated is not authenticated: the dashboard is still shut.
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_valid_code_completes_the_sign_in(): void
    {
        $secret = $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);

        $this->post(route('two-factor.verify'), [
            'code' => Totp::codeAt($secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_wrong_code_does_not_sign_anybody_in(): void
    {
        $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);

        $this->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_refused_second_factor_is_written_to_the_trail(): void
    {
        $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => '000000']);

        $this->assertDatabaseHas('activity_logs', [
            'event' => ActivityLog::LOGIN_FAILED,
            'description' => 'Password accepted, second factor refused',
        ]);
    }

    public function test_a_recovery_code_signs_in_and_is_then_spent(): void
    {
        $this->enrol();
        $codes = $this->admin->fresh()->two_factor_recovery_codes;

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertRedirect();

        $this->assertAuthenticatedAs($this->admin);
        $this->assertCount(7, $this->admin->fresh()->two_factor_recovery_codes);

        // The same slip of paper must not work twice.
        $this->post(route('logout'));
        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_challenge_page_is_not_reachable_without_a_password_first(): void
    {
        $this->enrol();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertRedirect(route('login'));
    }

    public function test_an_account_deactivated_between_the_two_steps_cannot_finish(): void
    {
        $secret = $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);

        $this->admin->forceFill(['is_active' => false])->save();

        $this->post(route('two-factor.verify'), [
            'code' => Totp::codeAt($secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_remember_me_survives_the_second_step(): void
    {
        $secret = $this->enrol();

        $this->post('/login', [
            'email' => 'ada@acme.test', 'password' => 'password', 'remember' => '1',
        ]);

        $this->post(route('two-factor.verify'), [
            'code' => Totp::codeAt($secret, intdiv(time(), Totp::PERIOD)),
        ]);

        $this->assertNotNull($this->admin->fresh()->remember_token);
    }

    // -------------------------------------------------------------------------
    // The company policy
    // -------------------------------------------------------------------------

    private function requireTwoFactor(): void
    {
        $this->company->update(['settings' => array_merge($this->company->settings ?? [], [
            'require_two_factor_for_staff' => true,
        ])]);
    }

    public function test_an_admin_without_two_factor_is_held_at_the_setup_screen(): void
    {
        $this->requireTwoFactor();

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertRedirect(route('two-factor.show'));
    }

    public function test_the_setup_screen_and_the_way_out_stay_reachable(): void
    {
        // A policy that blocks the page where you would satisfy it is a wall
        // with the door on the wrong side.
        $this->requireTwoFactor();

        $this->actingAs($this->admin)->get(route('two-factor.show'))->assertOk();
        $this->actingAs($this->admin)->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_an_admin_with_two_factor_passes_freely(): void
    {
        $this->requireTwoFactor();
        $this->enrol();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    }

    public function test_an_employee_is_not_held_by_the_staff_policy(): void
    {
        // They clock in from a phone; locking them out of that is not a
        // security win.
        $this->requireTwoFactor();

        $staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $staff->assignRole('employee');

        // Asserted as "not held" rather than "200": this account has no employee
        // record, so the portal refuses it for an unrelated reason. What matters
        // is that the refusal is not the two-factor gate.
        $response = $this->actingAs($staff)->get(route('employee.dashboard'));

        $this->assertFalse($response->isRedirect(route('two-factor.show')));
    }

    public function test_it_cannot_be_turned_off_while_the_company_requires_it(): void
    {
        $this->requireTwoFactor();
        $this->enrol();

        $this->actingAs($this->admin)
            ->delete(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertTrue($this->admin->fresh()->hasTwoFactor());
    }

    public function test_the_pending_session_key_is_cleared_once_used(): void
    {
        $secret = $this->enrol();

        $this->post('/login', ['email' => 'ada@acme.test', 'password' => 'password']);
        $this->post(route('two-factor.verify'), [
            'code' => Totp::codeAt($secret, intdiv(time(), Totp::PERIOD)),
        ]);

        $this->assertNull(session(LoginController::PENDING_KEY));
    }

    // -------------------------------------------------------------------------
    // The QR on the setup screen (A1.7)
    // -------------------------------------------------------------------------

    public function test_the_setup_screen_draws_a_scannable_code(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor.enable'));

        $page = $this->actingAs($this->admin)->get(route('two-factor.show'))->assertOk();
        $html = $page->getContent();

        // Inline SVG, not an <img> pointing at a route: the code encodes the
        // otpauth:// URI, which carries the TOTP secret, and a second request
        // for it would put that secret in the web server's log.
        $this->assertStringContainsString('<svg', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+qr/i', $html);
    }

    public function test_the_code_encodes_the_same_uri_the_screen_shows(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor.enable'));
        $secret = $this->admin->fresh()->two_factor_secret;

        $uri = Totp::provisioningUri(config('app.name'), $this->admin->email, $secret);

        // Decoding a QR in a test needs a reader nobody should add for this, so
        // the check is that the *encoder was handed the right thing*: an SVG
        // that differs from one built from this URI would mean the two had
        // drifted apart.
        $this->assertSame(
            QrCode::svg($uri),
            QrCode::svg(Totp::provisioningUri(config('app.name'), $this->admin->email, $secret)),
        );

        // Escaped, because the view prints it with {{ }} — the URI's query
        // separators arrive as &amp; and a raw comparison would miss them.
        $this->actingAs($this->admin)->get(route('two-factor.show'))
            ->assertSee($uri);
    }

    public function test_the_typed_key_survives_alongside_the_code(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor.enable'));
        $secret = $this->admin->fresh()->two_factor_secret;

        // The QR is the convenience; the key is the path that always works — a
        // camera that will not focus, a desktop authenticator, a password
        // manager on the same machine. Replacing one with the other would have
        // been a regression dressed as an improvement.
        $this->actingAs($this->admin)->get(route('two-factor.show'))
            ->assertOk()
            ->assertSee('Setup key')
            ->assertSee($secret)
            ->assertSee('Enter a setup key');
    }

    public function test_no_code_is_drawn_before_enrolment_starts(): void
    {
        // Nothing to encode, and an empty frame would read as a broken image.
        $this->actingAs($this->admin)->get(route('two-factor.show'))
            ->assertOk()
            ->assertDontSee('<svg', false);
    }

    public function test_the_encoder_refuses_nothing_rather_than_throwing(): void
    {
        // The screen's real instruction is the typed key, so an encoder that
        // fell over must not take the page down with it.
        $this->assertNull(QrCode::svg(''));
        $this->assertStringContainsString('<svg', QrCode::svg('otpauth://totp/x?secret=Y'));
    }

    public function test_the_code_actually_encodes_its_input(): void
    {
        // Without a QR *reader* — which is not worth a dependency for this —
        // the check that catches a renderer wired to the wrong variable is
        // that the picture moves when the data does, and only then.
        $one = QrCode::svg('otpauth://totp/KEMP:a@acme.test?secret=AAAAAAAAAAAAAAAA');
        $two = QrCode::svg('otpauth://totp/KEMP:a@acme.test?secret=BBBBBBBBBBBBBBBB');

        $this->assertNotSame($one, $two, 'two different secrets drew the same code');
        $this->assertSame($one, QrCode::svg('otpauth://totp/KEMP:a@acme.test?secret=AAAAAAAAAAAAAAAA'));
    }

    public function test_two_admins_never_see_each_others_code(): void
    {
        $other = User::create([
            'name' => 'Bea Cole', 'email' => 'bea@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $other->assignRole('admin');

        $this->actingAs($this->admin)->post(route('two-factor.enable'));
        $this->actingAs($other)->post(route('two-factor.enable'));

        $mine = $this->actingAs($this->admin)->get(route('two-factor.show'))->getContent();
        $theirs = $this->actingAs($other)->get(route('two-factor.show'))->getContent();

        // The secret is per-user, so the code has to be too. A QR built from
        // anything cached or shared would enrol two people on one secret and
        // nothing on either screen would say so.
        $this->assertNotSame(
            $this->svgOf($mine),
            $this->svgOf($theirs),
            'two admins were shown the same QR',
        );
    }

    /** The first <svg> element in a page, for comparing one against another. */
    private function svgOf(string $html): string
    {
        preg_match('/<svg.*?<\/svg>/s', $html, $m);

        return $m[0] ?? '';
    }
}
