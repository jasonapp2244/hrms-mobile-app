<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Web list page sizes come from config, not from the call site.
 *
 * There were twenty-three literal `paginate(n)` calls across the controllers
 * and five different values of n, with no rule anybody could state for which
 * list got which — it was drift, not design. The numbers themselves were kept;
 * what moved is where they live.
 *
 * The two cases worth pinning are the two that a later refactor could quietly
 * break: that a named list still gets its own size rather than the default, and
 * that an unknown name falls back instead of throwing. The second is the one
 * that turns a typo into a 500 on a screen that was working.
 */
class PageSizeWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->admin = User::create([
            'name' => 'Ada Root', 'email' => 'ada@acme.test',
            'password' => Hash::make('password'), 'company_id' => $company->id,
        ]);
        $this->admin->assignRole('admin');

        for ($i = 1; $i <= 6; $i++) {
            Department::create(['company_id' => $company->id, 'name' => 'Team ' . $i]);
        }
    }

    public function test_a_list_takes_its_size_from_config(): void
    {
        config(['pagination.web.default' => 2]);

        $response = $this->actingAs($this->admin)->get(route('departments.index'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('departments')->perPage());
    }

    public function test_a_named_list_keeps_its_own_size(): void
    {
        config([
            'pagination.web.default'               => 3,
            'pagination.web.lists.activity_logs'   => 11,
        ]);

        $response = $this->actingAs($this->admin)->get(route('activity.index'));

        $response->assertOk();
        $this->assertSame(11, $response->viewData('logs')->perPage());
    }

    public function test_an_unknown_list_name_falls_back_rather_than_failing(): void
    {
        // The departments list passes no name at all, which is the same code
        // path an unrecognised one takes. Emptying the map must not change it.
        config(['pagination.web.lists' => [], 'pagination.web.default' => 4]);

        $response = $this->actingAs($this->admin)->get(route('activity.index'));

        $response->assertOk();
        $this->assertSame(4, $response->viewData('logs')->perPage());
    }
}
