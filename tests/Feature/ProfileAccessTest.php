<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who can reach their own profile, and what they get when they do.
 *
 * The profile routes used to sit inside the admin|hr group. The effect was not
 * a visible error — it was a 403 on the only page where an employee or manager
 * can change their own password, with no link to it anywhere in the portal. So
 * the tests that matter most here are the two non-staff roles.
 *
 * The second theme is chrome. The view extends the admin layout for staff and
 * the portal layout for everyone else; handing an employee the admin sidebar
 * would fill their screen with links they are refused. Same for the breadcrumb
 * home link, which pointed at the dashboard — the exact page they cannot open.
 */
class ProfileAccessTest extends TestCase
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
    }

    private function userWith(string $role, string $password = 'CorrectHorse1'): User
    {
        $user = User::create([
            'name'       => ucfirst($role) . ' Person',
            'email'      => $role . '@acme.test',
            'password'   => Hash::make($password),
            'company_id' => $this->company->id,
        ]);

        $user->assignRole($role);

        return $user;
    }

    // -------------------------------------------------------------------------
    // Reachability — the actual fix
    // -------------------------------------------------------------------------

    #[DataProvider('signInRoles')]
    public function test_every_role_can_open_its_own_profile(string $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get(route('profile.index'))
            ->assertOk()
            ->assertSee('Change Password');
    }

    public static function signInRoles(): array
    {
        return [
            'admin'    => ['admin'],
            'hr'       => ['hr'],
            'employee' => ['employee'],
            'manager'  => ['manager'],
        ];
    }

    public function test_a_signed_out_visitor_is_sent_to_login(): void
    {
        $this->get(route('profile.index'))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Chrome — the right layout and a home link that works
    // -------------------------------------------------------------------------

    public function test_an_employee_gets_the_portal_layout_not_the_admin_sidebar(): void
    {
        $response = $this->actingAs($this->userWith('employee'))->get(route('profile.index'));

        $response->assertOk();
        // A link only the admin layout renders. Its absence is the assertion:
        // an employee seeing it would be looking at a sidebar of 403s.
        $response->assertDontSee(route('roles.index'));
        $response->assertDontSee(route('settings.index'));
    }

    public function test_the_staff_home_crumb_points_where_the_user_can_actually_go(): void
    {
        // It used to be hard-coded to route('dashboard'), which is the one page
        // a non-staff user is refused — so the crumb is staff-only now and has
        // to follow homeRoute() rather than assume.
        $this->actingAs($this->userWith('admin'))
            ->get(route('profile.index'))
            ->assertSee(route('dashboard'));
    }

    public function test_the_portal_still_offers_a_way_back(): void
    {
        // No breadcrumb in the portal — the nav pills are the way home, and
        // they must not have gone missing along with it.
        $this->actingAs($this->userWith('employee'))
            ->get(route('profile.index'))
            ->assertOk()
            ->assertSee(route('employee.dashboard'));
    }

    public function test_the_portal_offers_a_link_to_the_profile(): void
    {
        // Without this the page is reachable and undiscoverable, which is the
        // same thing as unreachable for the person who needs it.
        //
        // The portal needs a linked employee record to render at all — that
        // guard is deliberate and unrelated to this fix, so satisfy it rather
        // than work around it.
        $user = $this->userWith('employee');

        Employee::create([
            'company_id'    => $this->company->id,
            'user_id'       => $user->id,
            'employee_code' => 'E1',
            'first_name'    => 'Employee',
            'last_name'     => 'Person',
            'status'        => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee(route('profile.index'));
    }

    // -------------------------------------------------------------------------
    // Changing your own password
    // -------------------------------------------------------------------------

    public function test_an_employee_can_change_their_own_password(): void
    {
        $user = $this->userWith('employee');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password'      => 'CorrectHorse1',
            'password'              => 'BrandNewPass9',
            'password_confirmation' => 'BrandNewPass9',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('BrandNewPass9', $user->fresh()->password));
    }

    public function test_the_current_password_must_be_right(): void
    {
        $user = $this->userWith('employee');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password'      => 'NotThePassword',
            'password'              => 'BrandNewPass9',
            'password_confirmation' => 'BrandNewPass9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('CorrectHorse1', $user->fresh()->password));
    }

    public function test_a_manager_updating_their_profile_cannot_touch_anyone_else(): void
    {
        $manager = $this->userWith('manager');
        $other   = $this->userWith('employee');

        $this->actingAs($manager)->put(route('profile.update'), [
            'name'  => 'Renamed Manager',
            'email' => 'renamed@acme.test',
        ])->assertRedirect();

        $this->assertSame('Renamed Manager', $manager->fresh()->name);
        $this->assertSame('employee@acme.test', $other->fresh()->email);
    }
}
