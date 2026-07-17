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
            'configure-qr',
            'view-reports',
            'export-reports',
            'manage-leave',
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

        // Admin gets everything
        $admin->syncPermissions(Permission::all());

        // HR subset (seeded now, login disabled in Phase 1 — enabled later)
        $hr->syncPermissions([
            'view-dashboard', 'manage-departments', 'manage-designations',
            'manage-employees', 'import-employees', 'view-attendance',
            'manage-attendance', 'view-reports', 'export-reports', 'manage-leave',
        ]);

        // Employee (reserved for the mobile self-service phase)
        $employee->syncPermissions([
            'view-attendance',
        ]);
    }
}
