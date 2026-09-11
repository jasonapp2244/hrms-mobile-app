<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Create the `manager` role on a database that predates it (A10, Stage 12).
 *
 * **The role was only ever declared in `RolePermissionSeeder`, and the seeder
 * does not run on deploy.** `deploy.sh` runs `migrate --force` and nothing
 * else, deliberately — the seeder ends in `syncPermissions()`, and re-running
 * that against a live database would undo every change made through the Roles
 * & Permissions editor (A1.4). So every database seeded before Stage 12 has
 * admin, hr and employee and no manager at all.
 *
 * The symptom is quiet and easy to misread: the Roles & Permissions screen
 * lists three roles rather than four, and the Employees screen offers no
 * manager to assign, so `/manager/*` is unreachable for the whole company —
 * gated `role:manager`, a role nobody can hold. Nothing errors, and the code
 * for the manager area is all present and tested, which makes it look like a
 * missing feature rather than a missing row.
 *
 * Same division of labour as the `manage-announcements` migration beside this
 * one: the seeder keeps the full picture for a fresh install, this adds the one
 * thing an existing database is missing, and it is safe to run against a
 * database that already has it.
 */
return new class extends Migration
{
    public const ROLE = 'manager';

    /**
     * Scoped to the manager's own direct reports, every one of them — there is
     * no company-wide visibility here. Kept identical to the block in
     * `RolePermissionSeeder`; if one changes, change both.
     *
     * `manage-announcements` is **not** in this list, deliberately: it
     * broadcasts to a whole department or office, and a team lead speaking to
     * everyone is a different feature from a team lead speaking to their own
     * reports.
     */
    public const PERMISSIONS = [
        'view-attendance',
        'approve-leave',
        'approve-swaps',
        'view-team',
    ];

    public function up(): void
    {
        // Ordinary on a fresh install: `migrate` runs before `db:seed`, and the
        // seeder creates the role and every permission a moment later.
        if (! Role::query()->exists()) {
            return;
        }

        $role = Role::firstOrCreate(['name' => self::ROLE, 'guard_name' => 'web']);

        // Only on the run that creates it. If the role is already here, an
        // administrator may have adjusted it through the editor, and this
        // migration has no business overruling that — the same reason the
        // seeder is kept away from a live database.
        if (! $role->wasRecentlyCreated) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            // firstOrCreate rather than a lookup: `view-team` was seeded from
            // the start, but a database old enough to be missing the role may
            // also be missing a permission added alongside it, and a null here
            // would fail the whole deploy on a missing row.
            $role->givePermissionTo(Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        // The role only, not the permissions: `view-attendance` and
        // `approve-leave` belong to hr and employee too, and deleting them here
        // would strip those roles on the way past.
        Role::where('name', self::ROLE)->where('guard_name', 'web')->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
