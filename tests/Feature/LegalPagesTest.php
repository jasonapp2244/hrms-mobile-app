<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Google Play requires a privacy policy and a data-deletion route reachable
 * without an account. A reviewer has no login, and neither does somebody who
 * has left the company and wants their record removed — so the thing worth
 * testing is that these never fall behind a session.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_is_public(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy policy');
    }

    public function test_the_deletion_page_is_public(): void
    {
        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Deleting your account and data');
    }

    public function test_both_render_before_a_company_has_been_set_up(): void
    {
        // A fresh install has no company row. A reviewer may reach these pages
        // before anyone has finished configuring anything, and a 500 would fail
        // the review rather than merely look unfinished.
        $this->assertSame(0, Company::count());

        $this->get('/privacy')->assertOk();
        $this->get('/account-deletion')->assertOk();
    }

    public function test_the_employer_is_named_as_the_one_who_answers(): void
    {
        Company::create([
            'name' => 'Acme Ltd', 'timezone' => 'UTC', 'email' => 'hr@acme.test',
        ]);

        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Acme Ltd')
            // The employer holds the data and answers the request; the app is
            // the tool they use.
            ->assertSee('hr@acme.test');
    }

    public function test_the_policy_says_location_never_blocks_a_punch(): void
    {
        // The claim has to match the software. It does — every failure path in
        // the app's location lookup falls through to punching without one — and
        // a policy that overstated this would be the kind of inaccuracy that
        // matters.
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('never stops you clocking in', false);
    }

    public function test_the_pages_link_to_each_other(): void
    {
        // Play wants the deletion route reachable from the policy.
        $this->get('/privacy')->assertOk()->assertSee(route('legal.deletion'));
        $this->get('/account-deletion')->assertOk()->assertSee(route('legal.privacy'));
    }
}
