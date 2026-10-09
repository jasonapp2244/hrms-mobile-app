<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Timezones;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The company's timezone, chosen on the Company page (client request,
 * 2026-09-29: a US client, and the zone must be a choice on the dashboard).
 *
 * It used to be a free-text box wanting "America/New_York" typed exactly. It
 * is a list now, US zones first by name, and whatever is saved there is what
 * the phone is told.
 */
class CompanyTimezoneTest extends TestCase
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

    public function test_the_page_offers_only_us_zones_with_the_saved_one_chosen(): void
    {
        $page = $this->actingAs($this->admin)->get(route('company.index'))->assertOk();

        $page->assertSee('<select name="timezone"', false)
            ->assertDontSee('<input type="text" name="timezone"', false)
            ->assertSeeInOrder(['United States', 'Eastern Time', 'Central Time', 'Pacific Time'])
            ->assertDontSee('All timezones')
            ->assertDontSee('Asia/', false)
            ->assertDontSee('Europe/', false)
            ->assertSee('<option value="USD" selected', false)
            ->assertDontSee('<option value="EUR"', false)
            ->assertSee('<option value="America/New_York" selected', false)
            ->assertSee('Local time there now');
    }

    public function test_choosing_another_zone_is_saved_and_the_phone_is_told(): void
    {
        $this->actingAs($this->admin)->put(route('company.update'), [
            'name' => 'Acme', 'timezone' => 'America/Los_Angeles', 'currency' => 'USD',
        ])->assertRedirect();

        $this->assertSame('America/Los_Angeles', $this->company->fresh()->timezone);

        $this->actingAs($this->admin)->get(route('company.index'))
            ->assertSee('<option value="America/Los_Angeles" selected', false);

        // The app reads the zone from /auth/me; there is no second setting.
        // Guards forgotten so the token is resolved the way a phone's is — a
        // fresh user — rather than reusing the instance actingAs() left behind
        // with the old company already loaded on it.
        $this->app['auth']->forgetGuards();
        $token = $this->admin->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.company.timezone', 'America/Los_Angeles');
    }

    public function test_a_zone_outside_the_us_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('company.update'), [
            'name' => 'Acme', 'timezone' => 'Europe/London', 'currency' => 'USD',
        ])->assertSessionHasErrors('timezone');

        $this->assertSame('America/New_York', $this->company->fresh()->timezone);
    }

    public function test_a_currency_other_than_dollars_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('company.update'), [
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'EUR',
        ])->assertSessionHasErrors('currency');
    }

    public function test_a_company_already_on_another_zone_can_still_save_unchanged(): void
    {
        // Set before this rule existed. Saving the form for an unrelated edit
        // must not be refused, nor quietly move every shift to Eastern.
        $this->company->update(['timezone' => 'Europe/London', 'currency' => 'GBP']);

        $this->actingAs($this->admin)->get(route('company.index'))
            ->assertSee('Current setting')
            ->assertSee('<option value="Europe/London" selected', false)
            ->assertSee('<option value="GBP" selected', false);

        $this->actingAs($this->admin)->put(route('company.update'), [
            'name' => 'Acme Renamed', 'timezone' => 'Europe/London', 'currency' => 'GBP',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Acme Renamed', $this->company->fresh()->name);
    }

    public function test_a_made_up_zone_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('company.update'), [
            'name' => 'Acme', 'timezone' => 'America/Nowhere', 'currency' => 'USD',
        ])->assertSessionHasErrors('timezone');

        $this->assertSame('America/New_York', $this->company->fresh()->timezone);
    }

    public function test_the_list_is_the_us_zones_and_is_honest_about_arizona(): void
    {
        $groups = Timezones::grouped(new DateTimeImmutable('2026-07-01 12:00:00 UTC'));

        $this->assertSame(['United States'], array_keys($groups));
        $this->assertSame('America/New_York', array_key_first($groups['United States']));

        // Every US entry is a real identifier, or the page would offer a zone
        // the validator then refuses.
        foreach (array_keys($groups['United States']) as $id) {
            $this->assertContains($id, timezone_identifiers_list());
        }

        // In July, Denver is on daylight time and Phoenix is not — the reason
        // Arizona has its own line.
        $this->assertStringStartsWith('(UTC−06:00)', $groups['United States']['America/Denver']);
        $this->assertStringStartsWith('(UTC−07:00)', $groups['United States']['America/Phoenix']);
        $this->assertStringStartsWith('(UTC−04:00)', $groups['United States']['America/New_York']);
    }

    public function test_a_fresh_install_proposes_us_eastern(): void
    {
        $this->assertSame('America/New_York', Timezones::DEFAULT);
    }
}
