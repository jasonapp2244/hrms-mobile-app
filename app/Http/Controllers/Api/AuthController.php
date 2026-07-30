<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Token authentication for the mobile app.
 *
 * Sanctum personal access tokens rather than session cookies: a phone has no
 * cookie jar worth relying on, and a token can be revoked for one device
 * without signing the person out everywhere.
 */
class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => 'required|email',
            'password'    => 'required|string',
            // Names the token so a person can see and revoke "Ann's Pixel"
            // rather than an opaque row.
            'device_name' => 'required|string|max:100',
        ]);

        $user = User::where('email', $data['email'])->first();

        // One message for both a wrong address and a wrong password: saying
        // which was wrong tells an attacker which addresses exist.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->fail('invalid_credentials', 'Those details do not match our records.', 401);
        }

        if ($user->is_active === false) {
            return $this->fail('account_disabled', 'This account has been disabled. Contact HR.', 403);
        }

        // Same device name twice means the app reinstalled or re-authenticated;
        // the old token is dead weight and a second valid credential.
        $user->tokens()->where('name', $data['device_name'])->delete();

        $token = $user->createToken($data['device_name']);

        return $this->ok([
            'token' => $token->plainTextToken,
            'user'  => $this->userPayload($user),
        ]);
    }

    /** The signed-in user, for a client restoring a session on launch. */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => $this->userPayload($request->user())]);
    }

    /** Sign out this device only. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(['message' => 'Signed out.']);
    }

    /** Sign out everywhere — for a lost or stolen phone. */
    public function logoutAll(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->count();
        $request->user()->tokens()->delete();

        return $this->ok([
            'message'          => 'Signed out on all devices.',
            'tokens_revoked'   => $count,
        ]);
    }

    /** Devices currently holding a valid token. */
    public function devices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return $this->ok([
            'devices' => $request->user()->tokens()
                ->latest('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id'           => $token->id,
                    'name'         => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at'   => $token->created_at?->toIso8601String(),
                    'current'      => $token->id === $current->id,
                ]),
        ]);
    }

    /**
     * What the app needs to render its own shell: who this is, what they may
     * do, and the company timezone every displayed time depends on.
     */
    protected function userPayload(User $user): array
    {
        $employee = $user->employee;

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'roles'       => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'company'     => $user->company ? [
                'id'       => $user->company->id,
                'name'     => $user->company->name,
                'timezone' => $user->company->tz(),
                'currency' => $user->company->currency,
            ] : null,
            'employee'    => $employee ? [
                'id'            => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name'     => $employee->full_name,
                'department'    => $employee->department?->name,
                'designation'   => $employee->designation?->name,
                'office'        => $employee->office?->name,
                'work_mode'     => $employee->work_mode,
                'is_manager'    => $employee->isManager(),
            ] : null,
        ];
    }
}
