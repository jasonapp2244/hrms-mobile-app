<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->assertSameCompany($employee);

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

        // A4.21. The welcome email with the one-time sign-in code. Sent on top
        // of the flashed password, never instead of it: mail is not guaranteed
        // to be configured, so the password cannot simply be emailed and
        // forgotten about. It is flashed once, for the administrator to hand
        // over, and never stored in readable form.
        $invited = $this->sendInvite($user, $request->user());

        return back()
            ->with('success', "Sign-in account created for {$employee->full_name}."
                . ($invited ? " A welcome email with a sign-in QR code is on its way to {$user->email}." : ''))
            ->with('error', $invited ? null : 'The welcome email could not be sent. Check the mail settings, then use "Send welcome email".')
            ->with('generated_password', $generated);
    }

    /**
     * Send the welcome email again (A4.21) — lost, expired, or the person has
     * a new phone. The code in any earlier email stops working.
     */
    public function invite(Request $request, Employee $employee)
    {
        $this->assertSameCompany($employee);
        $user = $this->accountFor($employee);

        // The code signs a phone in as this account, so it is the same power
        // as setting its password: not HR's to use on an HR or admin login.
        if ($refusal = $this->refuseElevated($request, $user, 'send a sign-in code for')) {
            return $refusal;
        }

        if (! $user->is_active) {
            return back()->with('error', 'This sign-in account is disabled. Enable it before sending a welcome email.');
        }

        if (! $this->sendInvite($user, $request->user())) {
            return back()->with('error', 'The welcome email could not be sent. Check the mail settings and try again.');
        }

        return back()->with('success', "A new welcome email with a sign-in QR code is on its way to {$user->email}. Any earlier one no longer works.");
    }

    /**
     * Issue a code and send it. False when sending failed — the account is
     * made either way, and the caller says so instead of pretending.
     */
    private function sendInvite(User $user, User $by): bool
    {
        try {
            $user->notify(new EmployeeInvite(ActivationCode::issueFor($user, $by)));
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: "Sent a welcome email with a one-time sign-in code to {$user->email}",
            subject: $user,
        );

        return true;
    }

    /**
     * Attach a login that already exists to this employee record.
     *
     * `store()` only ever mints a new login, so an account created on its own —
     * the HR or admin user set up before anybody entered their employee row —
     * could never be joined to one short of tinker, and on the phone that
     * account then had no Clock, History, Leave or Schedule.
     *
     * Everything is checked again inside a locked transaction: two people
     * linking at once must not both succeed and leave one login behind two
     * people, which would let one person punch as another.
     */
    public function link(Request $request, Employee $employee)
    {
        $this->assertSameCompany($employee);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $actor = $request->user();

        $error = DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->first();
            $user = User::whereKey($data['user_id'])->lockForUpdate()->first();

            if ($employee->user_id) {
                return 'This employee already has a sign-in account.';
            }

            // Not "the same company as the actor" alone: a login with no company
            // at all would otherwise be linkable into any company's record.
            if ($user->company_id === null || (int) $user->company_id !== (int) $employee->company_id) {
                return 'That login belongs to a different company.';
            }

            if (Employee::where('user_id', $user->id)->exists()) {
                return 'That login is already linked to another employee.';
            }

            // Same split as store(): someone who may not grant HR or admin must
            // not be able to take over such a login by attaching it to a record.
            if (self::holdsElevatedRole($user) && ! $actor->can('manage-roles')) {
                return 'Only an administrator can link an HR or admin login.';
            }

            $employee->update(['user_id' => $user->id]);

            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Linked the existing login {$user->email} to {$employee->full_name}",
                subject: $user,
            );

            return null;
        });

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', "{$employee->full_name} is now linked to that login and can use the mobile app.");
    }

    /**
     * Logins of this company that no employee record points at yet — the
     * choices for link(). Elevated ones are left out for anybody who could not
     * link them anyway.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function linkableFor(?User $actor, Employee $employee)
    {
        if (! $actor || $employee->user_id) {
            return collect();
        }

        return User::query()
            ->where('company_id', $employee->company_id)
            ->whereNotIn('id', Employee::whereNotNull('user_id')->select('user_id'))
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->reject(fn (User $user) => self::holdsElevatedRole($user) && ! $actor->can('manage-roles'))
            ->values();
    }

    private static function holdsElevatedRole(User $user): bool
    {
        return $user->hasAnyRole(self::ELEVATED_ROLES);
    }

    /**
     * Turn away somebody without `manage-roles` acting on an HR or admin
     * login, or null to carry on — the store() split, applied to every action
     * that is as good as holding that login.
     */
    private function refuseElevated(Request $request, User $user, string $action)
    {
        if (self::holdsElevatedRole($user) && ! $request->user()->can('manage-roles')) {
            return back()->with('error', "Only an administrator can {$action} an HR or admin login.");
        }

        return null;
    }

    /** Issue a new password for an existing login. */
    public function resetPassword(Request $request, Employee $employee)
    {
        $this->assertSameCompany($employee);
        $user = $this->accountFor($employee);

        // The page hides this button on an HR or admin login; the route did
        // not, so HR could post here, set an administrator's password and sign
        // in as them.
        if ($refusal = $this->refuseElevated($request, $user, 'reset the password of')) {
            return $refusal;
        }

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
        $this->assertSameCompany($employee);
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
        $this->assertSameCompany($employee);
        $user = $this->accountFor($employee);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own sign-in account.');
        }

        // Same gap as resetPassword(): hidden on the page, open on the route.
        if ($refusal = $this->refuseElevated($request, $user, 'switch on or off')) {
            return $refusal;
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

    /**
     * Refuse an employee of another company. The route binds any employee id,
     * and `EmployeeController` guards its own screens the same way.
     */
    private function assertSameCompany(Employee $employee): void
    {
        abort_unless((int) $employee->company_id === $this->companyId(), 403);
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
