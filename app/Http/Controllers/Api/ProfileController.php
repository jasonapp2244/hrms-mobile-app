<?php

namespace App\Http\Controllers\Api;

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

        $user->update($data);

        return $this->ok([
            'message' => 'Profile updated.',
            'account' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
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
            return $this->fail('wrong_password', 'Your current password is incorrect.', 422, [
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        $current = $user->currentAccessToken();
        $signedOut = $user->tokens()->where('id', '!=', $current->id)->count();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        return $this->ok([
            'message'                  => 'Password changed.',
            'other_devices_signed_out' => $signedOut,
        ]);
    }
}
