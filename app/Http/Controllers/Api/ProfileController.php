<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The employee's own record and account settings.
 *
 * Deliberately read-mostly: an employee may correct how to reach them, but not
 * their department, manager, shift or hire date. Those are HR's to set, and an
 * app that let people edit them would be a hole in the org chart rather than a
 * convenience.
 */
class ProfileController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee?->load('department', 'designation', 'office', 'manager');

        return $this->ok([
            'account' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'avatar' => $user->avatar,
                'roles'  => $user->getRoleNames(),
            ],
            'employee' => $employee ? [
                'id'            => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name'     => $employee->full_name,
                'email'         => $employee->email,
                'phone'         => $employee->phone,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'gender'        => $employee->gender,
                'hire_date'     => $employee->hire_date?->toDateString(),
                'status'        => $employee->status,
                'work_mode'     => $employee->work_mode,
                'department'    => $employee->department?->name,
                'designation'   => $employee->designation?->name,
                'office'        => $employee->office?->name,
                'manager'       => $employee->manager?->full_name,
                'is_manager'    => $employee->isManager(),

                // A3.9, and the caller's own record rather than anybody
                // else's — the directory withholds exactly these fields about
                // a colleague, which is a statement about other people's
                // records and not about your own.
                'personal_email'             => $employee->personal_email,
                'address'                    => $employee->address,
                'city'                       => $employee->city,
                'country'                    => $employee->country,
                'emergency_contact_name'     => $employee->emergency_contact_name,
                'emergency_contact_phone'    => $employee->emergency_contact_phone,
                'emergency_contact_relation' => $employee->emergency_contact_relation,
            ] : null,
            // The standing shift, ignoring the roster — "the hours I am normally
            // on". What applies on a particular day comes from /attendance/today.
            'shift' => $employee?->shift ? [
                'id'            => $employee->shift->id,
                'name'          => $employee->shift->name,
                'start_time'    => $employee->shift->start_time,
                'end_time'      => $employee->shift->end_time,
                'working_hours' => $employee->shift->working_hours,
            ] : null,
            'company' => $user->company ? [
                'id'       => $user->company->id,
                'name'     => $user->company->name,
                'timezone' => $user->company->tz(),
                'currency' => $user->company->currency,
            ] : null,
        ]);
    }

    /** Contact details only — the same three fields the web profile page edits. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:30',
        ]);

        // The sign-in address changing is a security event; a new phone number
        // is not. Recording every contact edit would bury the one line that
        // matters under noise — and changing the address somebody signs in with
        // is step one of taking the account over by password reset.
        $addressChanged = $data['email'] !== $user->email;
        $wasEmail = $user->email;

        $user->update($data);

        if ($addressChanged) {
            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Changed their own sign-in address from {$wasEmail} to {$user->email}, from the mobile app",
                actor: $user,
                request: $request,
            );
        }

        return $this->ok([
            'message' => __('api.profile_updated'),
            'account' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    /**
     * The employee record's own contact details (B3.2).
     *
     * **A separate endpoint from [update], because it is a different record.**
     * That one writes `users` — the account — and needs `name` and `email` on
     * every call; this writes `employees`, and an account with no employee row
     * has nothing here to write at all. Folding them together would mean one
     * payload where half the required fields are meaningless half the time.
     *
     * **What is here and what is not follows one rule**: an employee may
     * correct how to reach them and who to call if something happens to them.
     * Date of birth, national id, blood group, hire date, department, manager
     * and shift are HR's to set and are absent — an app that let people edit
     * those would be a hole in the personnel record rather than a convenience.
     * The sign-in address is absent for a different reason: changing it is
     * account takeover in two steps, and an unlocked phone would be enough.
     *
     * `personal_email` is in, because it is plainly "how to reach me" and is
     * not a credential — nothing in authentication, password reset or
     * notification routing reads it.
     *
     * **Only the fields actually sent are written.** An omitted key is left
     * alone rather than being cleared, so a client that knows about six of
     * these cannot wipe the seventh by not having heard of it. An empty string
     * clears a field, which is how "I no longer have an emergency contact" is
     * said — the alternative is a field nobody can ever empty again.
     */
    public function updateDetails(Request $request): JsonResponse
    {
        $employee = $this->employee();

        // The same rules the web employee form uses (EmployeeController), so
        // one field cannot be valid on a desk and refused on a phone.
        $data = $request->validate([
            'personal_email'             => 'nullable|email|max:150',
            'address'                    => 'nullable|string|max:500',
            'city'                       => 'nullable|string|max:100',
            'country'                    => 'nullable|string|max:100',
            'emergency_contact_name'     => 'nullable|string|max:150',
            'emergency_contact_phone'    => 'nullable|string|max:30',
            'emergency_contact_relation' => 'nullable|string|max:60',
        ]);

        // Only what was sent, and '' means null so the column holds one kind of
        // empty rather than two that sort and compare differently.
        $changes = [];

        foreach ($data as $field => $value) {
            if (! $request->has($field)) {
                continue;
            }

            $trimmed = is_string($value) ? trim($value) : $value;
            $changes[$field] = ($trimmed === null || $trimmed === '') ? null : $trimmed;
        }

        if ($changes !== []) {
            $employee->update($changes);
        }

        return $this->ok([
            'message'  => __('api.profile_updated'),
            'employee' => [
                'personal_email'             => $employee->personal_email,
                'address'                    => $employee->address,
                'city'                       => $employee->city,
                'country'                    => $employee->country,
                'emergency_contact_name'     => $employee->emergency_contact_name,
                'emergency_contact_phone'    => $employee->emergency_contact_phone,
                'emergency_contact_relation' => $employee->emergency_contact_relation,
            ],
        ]);
    }

    /**
     * Change the password.
     *
     * The current one is required even though the caller already holds a valid
     * token: a phone left unlocked for a minute should not be enough to take the
     * account over. Every other device is signed out afterwards, because the
     * usual reason for changing a password is that someone else may know it —
     * leaving their session alive would defeat the change.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->fail('wrong_password', __('api.wrong_password'), 422, [
                'errors' => ['current_password' => [__('api.wrong_password')]],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        $current = $user->currentAccessToken();
        $signedOut = $user->tokens()->where('id', '!=', $current->id)->count();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        // `PASSWORD_CHANGED` had a label and a badge colour on the Activity Log
        // screen and was written by nothing at all — so a password changed by
        // whoever currently holds the account left no trace, which is the one
        // move an attacker makes on every account they take. Only an
        // HR-initiated reset was ever recorded.
        ActivityLog::record(
            event: ActivityLog::PASSWORD_CHANGED,
            description: $signedOut > 0
                ? "Changed their own password from the mobile app — {$signedOut} other device(s) signed out"
                : 'Changed their own password from the mobile app',
            actor: $user,
            request: $request,
        );

        return $this->ok([
            'message'                  => __('api.password_changed'),
            'other_devices_signed_out' => $signedOut,
        ]);
    }
}
