<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Grant `manage-announcements` to admin and HR (B5.5).
 *
 * **This has to be a migration, not the seeder.** `RolePermissionSeeder` is
 * where the permission is declared for a fresh install, but `deploy.sh` runs
 * `migrate --force` and nothing else — deliberately, because the seeder ends in
 * `syncPermissions()`, and re-running that on a live database would silently
 * undo every change made through the Roles & Permissions editor (A1.4). A
 * client who had taken `export-reports` away from HR would find it back after
 * the next deploy.
 *
 * So the seeder keeps the full picture for a new database, and this adds the
 * one new row to an existing one. It touches nothing else, and it is safe to
 * run against a database the seeder has already covered.
 */
return new class extends Migration
{
    public const PERMISSION = 'manage-announcements';

    public function up(): void
    {
        // Ordinary on a fresh install: `migrate` runs before `db:seed`, and the
        // seeder will create both the permission and the roles a moment later.
        if (! Role::query()->exists()) {
            return;
        }

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        // `givePermissionTo` rather than `syncPermissions`: additive, so
        // whatever else these roles have been given or had taken away stays as
        // the administrator left it.
        foreach (['admin', 'hr'] as $name) {
            Role::where('name', $name)->where('guard_name', 'web')->first()
                ?->givePermissionTo($permission);
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
