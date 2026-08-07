<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\QuickLogin;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The one-click sign-in panel on the login page.
 *
 * Two separate things are under test and only one of them is the feature. The
 * panel has to work — a client walking through roles should not be typing
 * passwords. But it publishes working credentials on a page that needs no
 * account to reach, so most of what follows is about the circumstances in which
 * it must refuse to render at all.
 *
 * The other theme is honesty: an earlier hard-coded version of this box listed
 * accounts that did not exist on a real install, so it offered buttons that
 * could not work. Every candidate is now checked against the database first,
 * and the tests below cover each way a candidate can be wrong.
 */
class QuickLoginTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->enable('boss@acme.test:CorrectHorse1');

        QuickLogin::forget();
    }

    private function enable(string $accounts, bool $on = true): void
    {
        config([
            'demo.quick_login'          => $on,
            'demo.quick_login_accounts' => $accounts,
        ]);

        QuickLogin::forget();
    }

    private function makeUser(string $email, string $password, string $role = 'admin', bool $active = true): User
    {
        $user = User::create([
            'name'       => 'Dana Boss',
            'email'      => $email,
            'password'   => Hash::make($password),
            'company_id' => $this->company->id,
            'is_active'  => $active,
        ]);

        $user->assignRole($role);

        return $user;
    }

    // -------------------------------------------------------------------------
    // The feature
    // -------------------------------------------------------------------------

    public function test_it_offers_an_account_that_really_works(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Demo accounts')
            ->assertSee('boss@acme.test');
    }

    public function test_the_role_shown_comes_from_the_database_not_the_config(): void
    {
        // The environment file names an email and a password and nothing else,
        // so a role label can never drift out of step with the real grant.
        $this->makeUser('boss@acme.test', 'CorrectHorse1', 'hr');

        $this->get(route('login'))->assertSee('HR');
    }

    public function test_the_offered_credentials_actually_sign_in(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');

        $account = QuickLogin::accounts()->firstOrFail();

        // The panel fills the ordinary form and submits it, so what it offers
        // has to survive the ordinary login path — including the role check
        // LoginController applies after the password matches.
        $this->post(route('login'), [
            'email'    => $account['email'],
            'password' => $account['password'],
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    // -------------------------------------------------------------------------
    // When it must not appear
    // -------------------------------------------------------------------------

    public function test_it_is_absent_when_switched_off(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');
        $this->enable('boss@acme.test:CorrectHorse1', on: false);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Demo accounts')
            ->assertDontSee('boss@acme.test');
    }

    public function test_production_overrides_the_flag_being_on(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');

        // The flag is on and the account is valid; only the environment differs.
        // This is the case that matters — a config cache built somewhere with
        // the flag set, shipped to a live server.
        app()['env'] = 'production';
        QuickLogin::forget();

        $this->assertFalse(QuickLogin::enabled());
        $this->assertTrue(QuickLogin::accounts()->isEmpty());

        $this->get(route('login'))->assertDontSee('boss@acme.test');
    }

    public function test_no_password_is_rendered_when_the_panel_is_off(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');
        $this->enable('boss@acme.test:CorrectHorse1', on: false);

        $this->get(route('login'))->assertDontSee('CorrectHorse1');
    }

    // -------------------------------------------------------------------------
    // Candidates that would not work, and so must not be shown
    // -------------------------------------------------------------------------

    public function test_it_skips_an_account_that_does_not_exist(): void
    {
        $this->enable('ghost@acme.test:CorrectHorse1');

        $this->get(route('login'))->assertDontSee('ghost@acme.test');
    }

    public function test_it_skips_an_account_whose_password_has_since_changed(): void
    {
        $this->makeUser('boss@acme.test', 'SomethingElse9');

        $this->get(route('login'))->assertDontSee('boss@acme.test');
    }

    public function test_it_skips_a_deactivated_account(): void
    {
        // LoginController turns these away after the password matches, so a
        // button for one would look like the app is broken.
        $this->makeUser('boss@acme.test', 'CorrectHorse1', active: false);

        $this->get(route('login'))->assertDontSee('boss@acme.test');
    }

    public function test_it_skips_an_account_with_no_sign_in_role(): void
    {
        User::create([
            'name'       => 'No Role',
            'email'      => 'boss@acme.test',
            'password'   => Hash::make('CorrectHorse1'),
            'company_id' => $this->company->id,
        ]);

        $this->get(route('login'))->assertDontSee('boss@acme.test');
    }

    public function test_an_empty_account_list_renders_nothing(): void
    {
        $this->enable('');

        $this->get(route('login'))->assertOk()->assertDontSee('Demo accounts');
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    public function test_a_password_may_contain_a_colon(): void
    {
        // Split on the first colon only. A password like `a:b` would otherwise
        // be silently truncated and the row would vanish with no explanation.
        $this->makeUser('boss@acme.test', 'pass:with:colons');
        $this->enable('boss@acme.test:pass:with:colons');

        $this->assertSame('pass:with:colons', QuickLogin::accounts()->firstOrFail()['password']);
    }

    public function test_it_tolerates_spacing_and_repeats_in_the_list(): void
    {
        $this->makeUser('boss@acme.test', 'CorrectHorse1');
        $this->enable('  boss@acme.test:CorrectHorse1 , , boss@acme.test:CorrectHorse1 ');

        $this->assertCount(1, QuickLogin::accounts());
    }
}
