<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Descending scope, not creation order.
     *
     * The default order is whatever the seeder inserted, which put `manager`
     * last because it was added two stages after the other three — so the role
     * with the narrowest reach sat above the one with none, for no reason a
     * reader could see. Anything this list does not name (a role added by hand
     * later) sorts after them alphabetically rather than vanishing.
     */
    private const ORDER = ['admin', 'hr', 'manager', 'employee'];

    public function index()
    {
        $roles = Role::withCount('users')
            ->with('permissions')
            ->get()
            ->sortBy(fn (Role $role) => [
                array_search($role->name, self::ORDER, true) === false
                    ? count(self::ORDER)
                    : array_search($role->name, self::ORDER, true),
                $role->name,
            ])
            ->values();

        $permissions = Permission::all();

        return view('roles.index', compact('roles', 'permissions'));
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', "Permissions updated for the '{$role->name}' role.");
    }
}
