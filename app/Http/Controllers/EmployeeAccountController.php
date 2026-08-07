<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in accounts for employee records.
 *
 * An employee row and a user row are separate things: the employee is the person
 * HR administers, the user is the login. Creating an employee deliberately does
 * not mint a login — plenty of staff are on the payroll and never touch the
 * system. But until this screen existed there was no way to mint one either,
 * short of tinker on the server, so anybody hired after go-live could be given a
 * record and then never sign in to the portal or the phone app.
 */
class EmployeeAccountController extends Controller
{
    /**
     * Roles this screen may hand out, and what it costs to hand them out.
     *
     * Creating a login is `manage-employees`, which HR holds — onboarding is
     * their job. Granting a role that can administer the system is
     * `manage-roles`, which only an admin holds. Without that split, HR could
     * mint an account, assign it `admin`, and sign in as one: a privilege
     * escalation wearing an onboarding form.
     */
    public const BASIC_ROLES = ['employee', 'manager'];

    public const ELEVATED_ROLES = ['hr', 'admin'];

    /** Display names. `ucfirst` alone renders the HR role as "Hr". */
    public const ROLE_LABELS = [
        'employee' => 'Employee',
        'manager'  => 'Manager',
        'hr'       => 'HR',
        'admin'    => 'Admin',
    ];

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role] ?? ucfirst((string) $role);
    }

    /** The roles the signed-in user is allowed to pick from. */
    public static function assignableBy(?User $actor): array
    {
        return $actor?->can('manage-roles')
            ? array_merge(self::BASIC_ROLES, self::ELEVATED_ROLES)
            : self::BASIC_ROLES;
    }

    /** Create the login and link it to the employee. */
    public function store(Request $request, Employee $employee)
    {
        if ($employee->user_id) {
            return back()->with('error', 'This employee already has a sign-in account.');
        }

        $allowed = self::assignableBy($request->user());

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role'  => ['required', Rule::in($allowed)],
            // Blank means "make one up for me", which is the common case: the
            // alternative is an administrator inventing a password under time
            // pressure, and those are always weak.
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'role.in' => 'You are not allowed to grant that role.',
        ]);

        $generated = null;
        if (empty($data['password'])) {
            $generated = Str::password(14, symbols: false);
        }

        $user = User::create([
            'name'       => $employee->full_name,
            'email'      => $data['email'],
            'password'   => Hash::make($generated ?? $data['password']),
            'company_id' => $employee->company_id,
            'phone'      => $employee->phone,
            'is_active'  => true,
        ]);

        $user->assignRole($data['role']);
        $employee->update(['user_id' => $user->id]);

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: "Created a sign-in account for {$employee->full_name} ({$data['email']}) with the {$data['role']} role",
            subject: $user,
        );

        // Mail is not guaranteed to be configured, so the password cannot simply
        // be emailed and forgotten about. It is flashed once, for the
        // administrator to hand over, and never stored in readable form.
        return back()
            ->with('success', "Sign-in account created for {$employee->full_name}.")
            ->with('generated_password', $generated);
    }

    /** Issue a new password for an existing login. */
    public function resetPassword(Request $request, Employee $employee)
    {
        $user = $this->accountFor($employee);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $generated = empty($data['password']) ? Str::password(14, symbols: false) : null;

        $user->update(['password' => Hash::make($generated ?? $data['password'])]);

        ActivityLog::record(
            event: ActivityLog::PASSWORD_RESET,
            description: "Reset the password for {$employee->full_name} ({$user->email})",
            subject: $user,
        );

        return back()
            ->with('success', "Password reset for {$employee->full_name}.")
            ->with('generated_password', $generated);
    }

    /** Change which role the login holds. */
    public function updateRole(Request $request, Employee $employee)
    {
        $user = $this->accountFor($employee);
        $allowed = self::assignableBy($request->user());

        $data = $request->validate([
            'role' => ['required', Rule::in($allowed)],
        ], [
            'role.in' => 'You are not allowed to grant that role.',
        ]);

        // Someone who cannot grant `admin` must not be able to take it away
        // either — otherwise HR could quietly demote every administrator.
        $current = $user->getRoleNames()->first();
        if ($current && ! in_array($current, $allowed, true)) {
            return back()->with('error', "This account holds the {$current} role. Only an administrator can change it.");
        }

        $user->syncRoles([$data['role']]);

        ActivityLog::record(
            event: ActivityLog::ROLE_CHANGED,
            description: "Changed {$employee->full_name} from {$current} to {$data['role']}",
            subject: $user,
        );

        return back()->with('success', "{$employee->full_name} now has the " . self::roleLabel($data['role']) . ' role.');
    }

    /**
     * Switch the login on or off.
     *
     * Deactivating rather than deleting: the user row is the author of every
     * punch, approval and audit row that account ever made, and removing it
     * would either orphan or cascade away the history it is evidence for.
     */
    public function toggleActive(Request $request, Employee $employee)
    {
        $user = $this->accountFor($employee);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own sign-in account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: ($user->is_active ? 'Reactivated' : 'Deactivated')
                . " the sign-in account for {$employee->full_name} ({$user->email})",
            subject: $user,
        );

        return back()->with(
            'success',
            $user->is_active
                ? "{$employee->full_name} can sign in again."
                : "{$employee->full_name} can no longer sign in."
        );
    }

    /** The linked account, or a 404 — every action here needs one to exist. */
    private function accountFor(Employee $employee): User
    {
        $user = $employee->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'account' => 'This employee has no sign-in account yet.',
            ]);
        }

        return $user;
    }
}
