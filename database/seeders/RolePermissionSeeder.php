<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // Granular permissions (permission-based access per the SOW)
        $permissions = [
            'view-dashboard',
            'manage-company',
            'manage-offices',
            'manage-departments',
            'manage-designations',
            'manage-employees',
            'import-employees',
            'view-attendance',
            'manage-attendance',
            'view-reports',
            'export-reports',
            'manage-leave',
            'approve-leave',
            'approve-swaps',
            'view-team',
            'manage-shifts',
            'manage-roles',
            'manage-settings',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Roles
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $hr = Role::firstOrCreate(['name' => 'hr', 'guard_name' => 'web']);
        $employee = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        // Admin gets everything
        $admin->syncPermissions(Permission::all());

        // HR subset. Covers day-to-day people operations: staff records, attendance,
        // schedules and reporting. Deliberately excludes company-level configuration
        // (company profile, offices, roles, settings), which stays with the admin.
        $hr->syncPermissions([
            'view-dashboard', 'manage-departments', 'manage-designations',
            'manage-employees', 'import-employees', 'view-attendance',
            'manage-attendance', 'view-reports', 'export-reports', 'manage-leave',
            'approve-leave', 'approve-swaps', 'view-team', 'manage-shifts',
        ]);

        // Employee — self-service portal only (check in/out + own attendance)
        $employee->syncPermissions([
            'view-attendance',
        ]);

        // Manager — a team lead. This role is always held *in addition to*
        // `employee`, never on its own: a manager is a member of staff who also
        // approves for their own reports. It grants no company-wide visibility;
        // every query it powers is scoped to the manager's direct reports.
        $manager->syncPermissions([
            'view-attendance',
            'approve-leave',
            'approve-swaps',
            'view-team',
        ]);
    }
}
