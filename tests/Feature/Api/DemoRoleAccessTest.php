<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What each of the four roles actually gets on the handset, using the accounts
 * `DemoDataSeeder` creates.
 *
 * **This exists because the demo data and the documentation disagreed, and
 * nothing said so.** `Feature-List_Web-and-App.md` records HR as having "the
 * employee screens only — desk-only by decision", and the role brief written
 * for the UI designer says the same. But the seeder gave `hr@emp.test` a user
 * and a role and **no employee record**, so on a handset it landed on the admin
 * empty state — "this account has no employee record, so there is nothing to
 * clock" — on all four employee screens. Two of the four roles could not be
 * demonstrated on a phone at all, and the only way to find that out was to sign
 * in and look.
 *
 * So the contract is pinned here rather than described anywhere else:
 *
 *   * **Employee** — the four employee screens, no team.
 *   * **Manager** — the same, plus the team endpoints. `is_manager` is a
 *     *relationship* (`Employee::isManager()` is "has subordinates"), not the
 *     role, which is why the app needs both it and `approve-leave` before it
 *     draws the Team tab.
 *   * **HR** — the employee screens, and **no team**, which is the decision
 *     rather than an omission: HR holds `approve-leave` as the second step of
 *     the approval chain but leads nobody, and the company-wide queue stays on
 *     the web dashboard.
 *   * **Admin** — 403 `no_employee_record` on the employee screens, by design.
 *     An administrator operates the system rather than working for the company,
 *     and that refusal is a designed screen with no retry button. A future
 *     seeder that "fixed" it by giving admin an employee record would hide the
 *     one state four screens are built to show.
 */
class DemoRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    // ================= the employee screens =================

    public function test_an_employee_reaches_the_clock(): void
    {
        $this->as('emily.johnson@acme.test');

        $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.is_manager', false);
    }

    public function test_hr_reaches_the_clock_like_anybody_else(): void
    {
        // The regression this file was written for. HR clocks in and books
        // their own leave — "desk-only" is about HR's *HR work*, not about
        // whether HR is a member of staff.
        $this->as('hr@emp.test');

        $this->getJson('/api/v1/attendance/today')->assertOk();
        $this->getJson('/api/v1/leave/balances')->assertOk();
    }

    public function test_an_administrator_is_told_there_is_nothing_to_clock(): void
    {
        $this->as('admin@emp.test');

        // Not a bug, and not to be "fixed" in the seeder: this refusal is the
        // empty state four screens are designed around.
        $this->getJson('/api/v1/attendance/today')
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }

    // ================= the team tab =================

    public function test_the_lead_is_a_manager_and_reaches_the_team(): void
    {
        $this->as('james.smith@acme.test');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.is_manager', true);

        $this->getJson('/api/v1/team/attendance')->assertOk();
        $this->getJson('/api/v1/team/roster')->assertOk();
        $this->getJson('/api/v1/team/leave-calendar')->assertOk();
    }

    public function test_an_ordinary_employee_gets_no_team(): void
    {
        $this->as('david.wilson@acme.test');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.is_manager', false);

        $this->getJson('/api/v1/team/attendance')->assertForbidden();
    }

    public function test_hr_leads_nobody_so_the_app_draws_no_team_tab(): void
    {
        $user = $this->as('hr@emp.test');

        // HR *does* hold approve-leave — it is the second step of the chain —
        // so the permission alone would have put a permanently empty Team tab
        // on an HR handset. The app asks for the relationship as well, and this
        // is the account that proves the two differ.
        $this->assertTrue($user->can('approve-leave'));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.is_manager', false);
    }

    // ================= the shape of the demo data =================

    public function test_hr_reports_to_nobody_and_is_not_on_the_leads_team(): void
    {
        $hr = User::where('email', 'hr@emp.test')->firstOrFail()->employee;
        $lead = User::where('email', 'james.smith@acme.test')->firstOrFail()->employee;

        $this->assertNotNull($hr, 'HR needs an employee record to use the app.');
        $this->assertNull($hr->manager_id);
        $this->assertNotContains($hr->id, $lead->subordinates()->pluck('id')->all());
    }

    public function test_re_seeding_does_not_duplicate_anybody(): void
    {
        // firstOrCreate throughout, and the reporting line only fills gaps —
        // so running the seeder twice on a demo box is safe.
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, User::where('email', 'hr@emp.test')->count());
        $this->assertSame(6, User::whereIn('email', [
            'hr@emp.test',
            'james.smith@acme.test',
            'emily.johnson@acme.test',
            'michael.brown@acme.test',
            'jessica.davis@acme.test',
            'david.wilson@acme.test',
        ])->count());
    }
}
