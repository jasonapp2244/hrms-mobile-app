<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Rate limiting on the password form.
 *
 * Password reset and the two-factor code were both throttled from the start;
 * the password form itself was not, which left the one door that opens on a
 * guess as the only one nobody was counting knocks at. The Lockout listener and
 * the Security panel's "Lockouts (24h)" tile were already written and could
 * never fire, because nothing in the web login ever raised the event.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);
    }

    private function user(string $password = 'CorrectHorse1'): User
    {
        $user = User::create([
            'name'       => 'Real Person',
            'email'      => 'real@acme.test',
            'password'   => Hash::make($password),
            'company_id' => $this->company->id,
            'is_active'  => true,
        ]);

        $user->assignRole('employee');

        return $user;
    }

    private function guess(string $email = 'real@acme.test', string $password = 'wrong')
    {
        return $this->post(route('login'), ['email' => $email, 'password' => $password]);
    }

    public function test_the_sixth_wrong_password_is_refused_outright(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->guess()->assertSessionHasErrors('email');
        }

        $this->guess();

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }

    public function test_the_lockout_is_written_to_the_activity_log(): void
    {
        // The tile on the Security panel counts these rows. Before the throttle
        // existed the event could not fire, so the tile read zero no matter
        // what was happening at the door.
        $this->user();

        for ($i = 0; $i < 6; $i++) {
            $this->guess();
        }

        $this->assertDatabaseHas('activity_logs', [
            'event'       => ActivityLog::LOCKOUT,
            'actor_label' => 'real@acme.test',
        ]);
    }

    public function test_a_locked_out_attacker_cannot_use_the_right_password(): void
    {
        // Otherwise the limit is decoration: guess five times, then walk in on
        // the sixth with the password you just found.
        $this->user();

        for ($i = 0; $i < 6; $i++) {
            $this->guess();
        }

        $this->guess('real@acme.test', 'CorrectHorse1');

        $this->assertGuest();
    }

    public function test_a_correct_password_clears_the_counter(): void
    {
        $this->user();

        for ($i = 0; $i < 4; $i++) {
            $this->guess();
        }

        $this->post(route('login'), [
            'email' => 'real@acme.test', 'password' => 'CorrectHorse1',
        ]);
        $this->assertAuthenticated();

        auth()->logout();
        session()->flush();

        // Four more would trip a counter that had not been reset.
        for ($i = 0; $i < 4; $i++) {
            $this->guess()->assertSessionHasErrors('email');
        }

        $this->assertStringNotContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }

    public function test_one_persons_mistakes_do_not_lock_out_a_colleague(): void
    {
        // The office-behind-one-NAT-address case: both share an IP, so a
        // counter keyed on source alone would have one lock out the other.
        $this->user();

        User::create([
            'name'       => 'Colleague',
            'email'      => 'colleague@acme.test',
            'password'   => Hash::make('CorrectHorse1'),
            'company_id' => $this->company->id,
            'is_active'  => true,
        ])->assignRole('employee');

        for ($i = 0; $i < 6; $i++) {
            $this->guess();
        }

        $this->post(route('login'), [
            'email' => 'colleague@acme.test', 'password' => 'CorrectHorse1',
        ]);

        $this->assertAuthenticated();
    }

    public function test_an_unknown_address_is_throttled_too(): void
    {
        // Guessing at the door rather than at a person still costs nothing to
        // try, so it has to be counted the same way.
        for ($i = 0; $i < 5; $i++) {
            $this->guess('nobody@acme.test');
        }

        $this->guess('nobody@acme.test');

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
    }

    public function test_the_counter_is_scoped_so_the_limiter_is_actually_keyed(): void
    {
        $this->user();

        for ($i = 0; $i < 6; $i++) {
            $this->guess();
        }

        // Same address, and the key includes it, so the limiter really is
        // holding state rather than the test passing by accident.
        $this->assertTrue(
            RateLimiter::tooManyAttempts('real@acme.test|127.0.0.1', 5)
        );
    }
}
