<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The migration that adds `manager` to a database seeded before it existed.
 *
 * **`RefreshDatabase` cannot catch the bug this migration fixes**, which is the
 * whole reason for this file. Every test starts on an empty database where
 * `migrate` runs before `db:seed`, so the migration takes its early return and
 * the seeder creates all four roles a moment later — exactly the path a fresh
 * install takes, and the one path where nothing was ever broken.
 *
 * The broken path is a database seeded months ago, migrated since, and never
 * re-seeded, because `deploy.sh` runs `migrate --force` and deliberately never
 * seeds. That state is reached here by seeding and then deleting the role.
 */
class ManagerRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** The migration under test, loaded the way Laravel loads it. */
    private function migration(): object
    {
        return require database_path('migrations/2026_09_12_000001_create_manager_role.php');
    }

    private function forgetPermissionCache(): void
    {
        app()['cache']->forget('spatie.permission.cache');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_it_creates_the_manager_role_on_a_database_that_lost_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // The state every pre-Stage-12 database is actually in: three roles,
        // the permissions all present, and no manager.
        Role::where('name', 'manager')->delete();
        $this->forgetPermissionCache();
        $this->assertNull(Role::where('name', 'manager')->first());

        $this->migration()->up();
        $this->forgetPermissionCache();

        $manager = Role::where('name', 'manager')->where('guard_name', 'web')->first();
        $this->assertNotNull($manager, 'the migration did not create the role');

        foreach (['view-attendance', 'approve-leave', 'approve-swaps', 'view-team'] as $permission) {
            $this->assertTrue(
                $manager->hasPermissionTo($permission),
                "manager is missing {$permission}",
            );
        }
    }

    public function test_the_manager_never_gets_company_wide_reach(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Role::where('name', 'manager')->delete();
        $this->forgetPermissionCache();

        $this->migration()->up();
        $this->forgetPermissionCache();

        $manager = Role::where('name', 'manager')->first();

        // The point of the role. If a future edit widens this list, these are
        // the ones that would turn a team lead into a second HR account —
        // manage-announcements above all, which broadcasts to a whole office.
        foreach ([
            'manage-employees',
            'manage-announcements',
            'manage-settings',
            'manage-roles',
            'export-reports',
        ] as $permission) {
            $this->assertFalse(
                $manager->hasPermissionTo($permission),
                "manager should not hold {$permission}",
            );
        }
    }

    public function test_it_leaves_an_existing_manager_role_exactly_as_the_administrator_left_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // An administrator has been in the Roles & Permissions editor and taken
        // approvals away from this role. Re-running the migration on the next
        // deploy must not hand them back — that is the bug the seeder is kept
        // off a live database to avoid, and it would be no better here.
        $manager = Role::where('name', 'manager')->firstOrFail();
        $manager->syncPermissions(['view-team']);
        $this->forgetPermissionCache();

        $this->migration()->up();
        $this->forgetPermissionCache();

        $manager = Role::where('name', 'manager')->firstOrFail();
        $this->assertTrue($manager->hasPermissionTo('view-team'));
        $this->assertFalse(
            $manager->hasPermissionTo('approve-leave'),
            'the migration overruled a change made in the roles editor',
        );
    }

    public function test_it_does_nothing_on_a_database_with_no_roles_at_all(): void
    {
        // A fresh install: migrate runs first, and the seeder has not been
        // anywhere near this database yet. Creating a role here would leave a
        // half-built one for the seeder to trip over.
        Role::query()->delete();
        Permission::query()->delete();
        $this->forgetPermissionCache();

        $this->migration()->up();

        $this->assertSame(0, Role::query()->count());
    }

    public function test_the_roles_screen_lists_the_manager_once_it_exists(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('roles.index'));

        $response->assertOk();
        $response->assertSee('Manager');
        // Ordered by descending scope rather than the order the seeder inserted
        // them, which had put the manager below the employee.
        $response->assertSeeInOrder(['Admin', 'HR', 'Manager', 'Employee']);
    }
}
