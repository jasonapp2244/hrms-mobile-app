<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CrashReport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Crash reports from the mobile app (B6.5).
 *
 * The two things worth pinning are that it accepts a report from a handset with
 * no session — the crash that stops the app opening is the one that matters
 * most, and an authenticated endpoint would collect every crash but that one —
 * and that being a public write does not make it a place to dump text.
 */
class CrashReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function report(array $overrides = []): array
    {
        return array_merge([
            'exception'   => '_TypeError',
            'message'     => "type 'Null' is not a subtype of type 'String'",
            'stack'       => "#0 PunchScreen.build (package:attendance/screens/punch_screen.dart:88:14)\n"
                . '#1 StatefulElement.build (package:flutter/src/widgets/framework.dart:5666:27)',
            'platform'    => 'android',
            'app_version' => '1.0.0',
            'os_version'  => 'Android 14 (API 34), google/panther',
            'occurred_at' => now()->subMinutes(20)->toIso8601String(),
        ], $overrides);
    }

    protected function send(array $reports): TestResponse
    {
        return $this->postJson('/api/v1/app/crashes', ['reports' => $reports]);
    }

    public function test_a_handset_with_no_session_can_still_report(): void
    {
        // The whole reason this endpoint is public. A crash on the way to the
        // login screen belongs to nobody, and is the most important one there
        // is — the app not opening is its worst failure.
        $this->send([$this->report()])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', 1);

        $crash = CrashReport::sole();

        $this->assertNull($crash->user_id);
        $this->assertNull($crash->company_id);
        $this->assertSame('_TypeError', $crash->exception);
    }

    public function test_a_token_attributes_the_report(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $company = Company::create(['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD']);
        $user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $user->assignRole('employee');

        Sanctum::actingAs($user);

        $this->send([$this->report()])->assertCreated();

        $crash = CrashReport::sole();

        $this->assertSame($user->id, $crash->user_id);
        $this->assertSame($company->id, $crash->company_id);
    }

    public function test_reports_from_one_place_share_a_fingerprint(): void
    {
        // What makes the screen readable: one row per bug, however many
        // handsets hit it.
        $this->send([$this->report(['os_version' => 'Android 14, google/panther'])])->assertCreated();
        $this->send([$this->report(['os_version' => 'Android 13, samsung/s22', 'app_version' => '1.1.0'])])->assertCreated();

        $this->assertSame(1, CrashReport::distinct()->count('fingerprint'));
    }

    public function test_a_crash_somewhere_else_does_not(): void
    {
        $this->send([$this->report()])->assertCreated();
        $this->send([$this->report([
            'stack' => '#0 LeaveScreen.build (package:attendance/screens/leave_screen.dart:40:9)',
        ])])->assertCreated();

        $this->assertSame(2, CrashReport::distinct()->count('fingerprint'));
    }

    public function test_the_same_method_on_a_different_line_is_a_different_bug(): void
    {
        // Two crashes in one method on different lines are usually two bugs,
        // and merging them hides one of them.
        $this->send([$this->report()])->assertCreated();
        $this->send([$this->report([
            'stack' => "#0 PunchScreen.build (package:attendance/screens/punch_screen.dart:141:14)\n"
                . '#1 StatefulElement.build (package:flutter/src/widgets/framework.dart:5666:27)',
        ])])->assertCreated();

        $this->assertSame(2, CrashReport::distinct()->count('fingerprint'));
    }

    public function test_it_keeps_the_time_the_handset_reports(): void
    {
        // Written on the phone at the moment of the crash and delivered on the
        // next launch, which may be the next morning. Stamping it on arrival
        // would put every report at the same minute.
        $when = now()->subDays(2)->startOfMinute();

        $this->send([$this->report(['occurred_at' => $when->toIso8601String()])])
            ->assertCreated();

        $this->assertSame(
            $when->toDateTimeString(),
            CrashReport::sole()->occurred_at->toDateTimeString(),
        );
    }

    public function test_an_impossible_time_falls_back_to_arrival(): void
    {
        // A phone with a wrong clock would otherwise sort itself to the top of
        // the screen for ever.
        foreach ([now()->addYear(), now()->subYears(3)] as $bogus) {
            CrashReport::query()->delete();

            $this->send([$this->report(['occurred_at' => $bogus->toIso8601String()])])
                ->assertCreated();

            $this->assertTrue(
                CrashReport::sole()->occurred_at->diffInMinutes(now()) < 2,
                'A time outside the accepted window should be replaced with the arrival time.',
            );
        }
    }

    public function test_it_refuses_to_be_a_place_to_dump_text(): void
    {
        // Public write, so every field is capped rather than trusted.
        $this->send([$this->report(['stack' => str_repeat('x', CrashReport::STACK_LIMIT + 1)])])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->send([$this->report(['message' => str_repeat('x', 501)])])
            ->assertStatus(422);

        $this->send(array_fill(0, 6, $this->report()))
            ->assertStatus(422);

        $this->assertSame(0, CrashReport::count());
    }

    public function test_a_report_with_no_exception_is_not_a_report(): void
    {
        $this->send([$this->report(['exception' => ''])])->assertStatus(422);
        $this->postJson('/api/v1/app/crashes', [])->assertStatus(422);
    }

    public function test_a_batch_is_stored_whole(): void
    {
        $this->send([
            $this->report(),
            $this->report(['exception' => 'StateError', 'stack' => '#0 Session.restore (session.dart:120:5)']),
        ])->assertCreated()->assertJsonPath('stored', 2);

        $this->assertSame(2, CrashReport::count());
    }

    public function test_a_report_cannot_be_rewritten_afterwards(): void
    {
        $this->send([$this->report()])->assertCreated();

        $crash = CrashReport::sole();
        $crash->message = 'something else';

        $this->expectException(\RuntimeException::class);
        $crash->save();
    }
}
