<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Env;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The app gate (B6.6).
 *
 * Almost every test here is about the endpoint refusing to block. It is the one
 * piece of the API that can stop an entire company clocking in, it does so from
 * a value typed into an env file, and nothing on the server side goes wrong
 * when it fires by mistake — so the cases worth pinning are the ones where it
 * must let an old build through rather than the one where it stops it.
 *
 * No database: the endpoint reads config only, on purpose. The moment it is
 * most needed — a migration in progress — is the moment the database is the
 * thing that is unavailable.
 */
class AppStatusApiTest extends TestCase
{
    protected function ask(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/app/status?' . http_build_query($query));
    }

    public function test_it_needs_no_token(): void
    {
        // A gate reachable only with a token cannot explain why signing in is
        // failing, which is the only question anybody has during a window.
        $this->ask()->assertOk()->assertJsonPath('action', 'ok');
    }

    public function test_with_no_floor_configured_every_build_is_accepted(): void
    {
        config(['mobile.minimum_version' => null]);

        $this->ask(['version' => '0.0.1', 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('action', 'ok')
            ->assertJsonPath('minimum_version', null)
            ->assertJsonPath('store_url', null);
    }

    public function test_a_build_below_the_floor_is_sent_to_the_store(): void
    {
        config([
            'mobile.minimum_version'      => '1.2.0',
            'mobile.latest_version'       => '1.4.0',
            'mobile.store_url.android'    => 'https://play.google.com/store/apps/details?id=com.hrms.attendance',
        ]);

        $this->ask(['version' => '1.1.9', 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('action', 'update_required')
            ->assertJsonPath('minimum_version', '1.2.0')
            ->assertJsonPath('latest_version', '1.4.0')
            ->assertJsonPath('store_url', 'https://play.google.com/store/apps/details?id=com.hrms.attendance')
            ->assertJsonPath('ok', true);
    }

    public function test_the_build_on_the_floor_is_accepted(): void
    {
        config([
            'mobile.minimum_version'   => '1.2.0',
            'mobile.store_url.android' => 'https://play.google.com/x',
        ]);

        $this->ask(['version' => '1.2.0', 'platform' => 'android'])
            ->assertJsonPath('action', 'ok');

        // And a build number under the same version is the same version.
        $this->ask(['version' => '1.2.0+91', 'platform' => 'android'])
            ->assertJsonPath('action', 'ok');
    }

    public function test_each_platform_gets_its_own_link(): void
    {
        config([
            'mobile.minimum_version'   => '2.0.0',
            'mobile.store_url.android' => 'https://play.google.com/x',
            'mobile.store_url.ios'     => 'https://apps.apple.com/x',
        ]);

        $this->ask(['version' => '1.0.0', 'platform' => 'ios'])
            ->assertJsonPath('store_url', 'https://apps.apple.com/x');
    }

    public function test_it_will_not_block_a_platform_it_cannot_send_anywhere(): void
    {
        // iOS is behind the floor too, but there is no App Store link. An
        // update screen whose button does nothing is worse than an old build:
        // it cannot be dismissed and it cannot be acted on. Preflight refuses
        // this combination at deploy time; this is the runtime half.
        config([
            'mobile.minimum_version'   => '2.0.0',
            'mobile.store_url.android' => 'https://play.google.com/x',
            'mobile.store_url.ios'     => null,
        ]);

        $this->ask(['version' => '1.0.0', 'platform' => 'ios'])
            ->assertJsonPath('action', 'ok');
    }

    public function test_a_build_that_sends_no_version_is_not_blocked(): void
    {
        // The build that predates this endpoint sends neither parameter, and
        // is exactly the build a floor would be aimed at — but guessing it is
        // old on no evidence is how a gate locks out something it should not.
        config([
            'mobile.minimum_version'   => '9.9.9',
            'mobile.store_url.android' => 'https://play.google.com/x',
        ]);

        $this->ask()->assertJsonPath('action', 'ok');
        $this->ask(['platform' => 'android'])->assertJsonPath('action', 'ok');
    }

    public function test_junk_in_either_parameter_answers_rather_than_refuses(): void
    {
        config([
            'mobile.minimum_version'   => '2.0.0',
            'mobile.store_url.android' => 'https://play.google.com/x',
        ]);

        // A 422 would be the one shape the app cannot act on: it asks this
        // question before it knows anything, so a refusal leaves it with no
        // verdict at all.
        $this->ask(['version' => 'nightly', 'platform' => 'android'])
            ->assertOk()->assertJsonPath('action', 'ok');

        $this->ask(['version' => '1.0.0', 'platform' => 'symbian'])
            ->assertOk()->assertJsonPath('action', 'ok');

        $this->getJson('/api/v1/app/status?version[]=1&platform[]=android')
            ->assertOk()->assertJsonPath('action', 'ok');
    }

    public function test_maintenance_stops_everything_and_says_why(): void
    {
        config([
            'mobile.maintenance'         => true,
            'mobile.maintenance_message' => 'Back at 6pm.',
        ]);

        $this->ask(['version' => '9.9.9', 'platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('action', 'maintenance')
            ->assertJsonPath('message', 'Back at 6pm.');
    }

    public function test_maintenance_outranks_the_version_check(): void
    {
        // There is no point telling somebody to fetch a build that also cannot
        // reach the server.
        config([
            'mobile.maintenance'       => true,
            'mobile.minimum_version'   => '9.9.9',
            'mobile.store_url.android' => 'https://play.google.com/x',
        ]);

        $this->ask(['version' => '1.0.0', 'platform' => 'android'])
            ->assertJsonPath('action', 'maintenance')
            ->assertJsonPath('store_url', null);
    }

    public function test_the_maintenance_message_is_never_empty(): void
    {
        // `MOBILE_MAINTENANCE_MESSAGE=` with nothing after it reads as an empty
        // string rather than as absent, so an env() default would not fire and
        // everybody would get a blank screen at the worst possible moment.
        // Read from the config file itself, because that is where the guard is
        // — overriding config() at runtime would skip the expression under test.
        Env::getRepository()->set('MOBILE_MAINTENANCE_MESSAGE', '');

        try {
            $config = require config_path('mobile.php');
            $this->assertNotEmpty($config['maintenance_message']);
        } finally {
            Env::getRepository()->clear('MOBILE_MAINTENANCE_MESSAGE');
        }
    }
}
