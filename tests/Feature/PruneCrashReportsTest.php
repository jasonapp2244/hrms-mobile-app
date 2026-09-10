<?php

namespace Tests\Feature;

use App\Models\CrashReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nightly housekeeping for `crash_reports` (B6.5).
 */
class PruneCrashReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function crash(int $daysAgo): CrashReport
    {
        $crash = CrashReport::create([
            'exception'   => 'StateError',
            'stack'       => '#0 Session.restore (session.dart:120:5)',
            'fingerprint' => CrashReport::fingerprintFor('StateError', '#0 Session.restore'),
            'occurred_at' => now()->subDays($daysAgo),
        ]);

        // created_at is what the window is measured on, and the model refuses
        // ordinary updates — this is the fixture setting up its own history.
        $crash->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $crash->fresh();
    }

    public function test_it_keeps_what_is_inside_the_window(): void
    {
        config(['mobile.crash_retention_days' => 90]);

        $this->crash(10);
        $this->crash(89);

        $this->artisan('crashes:prune')->assertSuccessful();

        $this->assertSame(2, CrashReport::count());
    }

    public function test_it_drops_what_is_past_it(): void
    {
        config(['mobile.crash_retention_days' => 90]);

        $this->crash(10);
        $this->crash(200);

        $this->artisan('crashes:prune')->assertSuccessful();

        $this->assertSame(1, CrashReport::count());
    }

    public function test_the_window_is_measured_on_arrival_not_on_the_handsets_clock(): void
    {
        // A phone with a wrong clock would otherwise age its own report out the
        // moment it arrived, and the report of a crash caused by that very
        // clock is one worth keeping.
        config(['mobile.crash_retention_days' => 90]);

        $crash = $this->crash(1);
        $crash->forceFill(['occurred_at' => now()->subYears(3)])->saveQuietly();

        $this->artisan('crashes:prune')->assertSuccessful();

        $this->assertSame(1, CrashReport::count());
    }

    public function test_zero_days_keeps_everything(): void
    {
        // `--days=0` is the way to say "keep it all". Read with `??` rather
        // than `?:`, or "0" reads as absent and the configured window deletes
        // exactly what the flag asked to spare.
        $this->crash(500);

        $this->artisan('crashes:prune', ['--days' => 0])->assertSuccessful();

        $this->assertSame(1, CrashReport::count());

        config(['mobile.crash_retention_days' => 0]);
        $this->artisan('crashes:prune')->assertSuccessful();

        $this->assertSame(1, CrashReport::count());
    }

    public function test_the_flag_overrides_the_configured_window(): void
    {
        config(['mobile.crash_retention_days' => 365]);

        $this->crash(30);

        $this->artisan('crashes:prune', ['--days' => 7])->assertSuccessful();

        $this->assertSame(0, CrashReport::count());
    }
}
