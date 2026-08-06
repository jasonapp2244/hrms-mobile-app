<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Forgot password, on the web and from the app.
 *
 * Two things are being protected here and they pull in opposite directions: a
 * locked-out administrator has to be able to get back in, and the form must not
 * become a way of asking which addresses belong to staff. Most of these tests
 * are about the second one.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
    }

    public function test_the_login_page_offers_a_way_out(): void
    {
        // Without this link the flow may as well not exist — nobody types
        // /forgot-password from memory.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'));
    }

    public function test_the_request_form_renders(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot Password?');
    }

    public function test_a_known_address_is_sent_a_link(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'ann@acme.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($this->user, PasswordResetLink::class);
    }

    public function test_an_unknown_address_is_answered_identically_and_mails_nobody(): void
    {
        Notification::fake();

        $known = $this->post(route('password.email'), ['email' => 'ann@acme.test']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@acme.test']);

        // Byte-for-byte the same, or the difference is the answer to "does this
        // person work here?".
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );

        Notification::assertCount(1);
    }

    public function test_a_deactivated_account_cannot_let_itself_back_in(): void
    {
        Notification::fake();

        $this->user->update(['is_active' => false]);

        $this->post(route('password.email'), ['email' => 'ann@acme.test'])
            ->assertSessionHas('status');

        // Same reassuring message, no link. Login already refuses this account;
        // reset must not be the way around that.
        Notification::assertNothingSent();
    }

    public function test_a_valid_token_sets_the_new_password(): void
    {
        $token = Password::createToken($this->user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'ann@acme.test',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-much-better-one', $this->user->fresh()->password));
    }

    public function test_resetting_cuts_off_the_mobile_app_and_its_push(): void
    {
        // The reason someone resets is often that somebody else has the
        // account. A Sanctum token outlives any web session, so leaving it
        // alive would make the reset cosmetic.
        $this->user->createToken('Ann\'s Pixel');
        PushDevice::create([
            'user_id' => $this->user->id,
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ]);

        $this->post(route('password.update'), [
            'token' => Password::createToken($this->user),
            'email' => 'ann@acme.test',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(0, $this->user->pushDevices()->count());
    }

    public function test_a_bad_token_is_refused_and_the_password_stands(): void
    {
        $this->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'ann@acme.test',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_a_token_works_once(): void
    {
        $token = Password::createToken($this->user);
        $payload = [
            'token' => $token,
            'email' => 'ann@acme.test',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ];

        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));

        // A reset link forwarded, logged by a mail gateway or left in an inbox
        // must not still be live afterwards.
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->post(route('password.update'), [
            'token' => Password::createToken($this->user),
            'email' => 'ann@acme.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_a_mistyped_confirmation_is_rejected(): void
    {
        $this->post(route('password.update'), [
            'token' => Password::createToken($this->user),
            'email' => 'ann@acme.test',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-typo',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_the_api_sends_a_link_for_the_app(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ann@acme.test'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Notification::assertSentTo($this->user, PasswordResetLink::class);
    }

    public function test_the_api_does_not_reveal_whether_an_address_exists(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ann@acme.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@acme.test']);

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->status(), $unknown->status());

        Notification::assertCount(1);
    }

    public function test_the_reset_link_goes_by_mail_only(): void
    {
        // Not the in-app bell and not push: somebody who cannot sign in cannot
        // read a database notification, and a reset link on a lock screen is a
        // reset link for whoever is holding the phone.
        $this->assertSame(['mail'], (new PasswordResetLink('tok'))->via($this->user));
    }
}
