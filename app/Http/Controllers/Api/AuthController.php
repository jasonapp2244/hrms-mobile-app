<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

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
            // B1.6. The app's own UUID for this handset, kept in the keystore.
            // Optional on the wire so an older build still signs in — it simply
            // cannot be bound, which is checked below rather than here.
            'device_id' => 'nullable|string|max:100',
            'platform'  => 'nullable|string|max:20',
        ]);

        $user = User::where('email', $data['email'])->first();

        // One message for both a wrong address and a wrong password: saying
        // which was wrong tells an attacker which addresses exist.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Dispatched rather than logged here. The security trail (A1.8)
            // hangs off the framework's auth events on purpose, so that one
            // listener covers every door — and its own comment already names
            // this endpoint as one of them. It never heard from us, because
            // token login checks the hash itself and never calls Auth::attempt,
            // so until now not one sign-in from a handset reached the trail.
            event(new Failed('sanctum', $user, ['email' => $data['email']]));

            return $this->fail('invalid_credentials', __('api.invalid_credentials'), 401);
        }

        if ($user->is_active === false) {
            // Recorded directly, and this is the one case that does not go
            // through an event: the framework has no "credentials were right
            // but the account is switched off" event to raise, and folding it
            // into a plain failure would lose the distinction. Somebody holding
            // working credentials for a disabled account is the most
            // interesting line on this screen.
            ActivityLog::record(
                event: ActivityLog::LOGIN_FAILED,
                description: 'Correct password on a disabled account — turned away at the mobile app',
                actor: $user,
                actorLabel: $user->email,
                request: $request,
            );

            return $this->fail('account_disabled', __('api.account_disabled'), 403);
        }

        // B1.6. After the password, and before a token exists: a refusal here
        // must not hand out a credential, and it must not happen to somebody
        // who typed the wrong password — they have already been turned away
        // above with a message that says nothing about devices.
        if ($refusal = $this->refuseUntrustedDevice($request, $user, $data)) {
            return $refusal;
        }

        // Same device name twice means the app reinstalled or re-authenticated;
        // the old token is dead weight and a second valid credential.
        $user->tokens()->where('name', $data['device_name'])->delete();

        $token = $user->createToken($data['device_name']);

        // The listener turns this into "Signed in from the mobile app". The
        // handset's name is not in the line because it is already on the
        // devices list, and the trail's job here is the fact and the address.
        event(new Login('sanctum', $user, false));

        return $this->ok([
            'token' => $token->plainTextToken,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * Start a password reset from the app.
     *
     * The app's part ends here. The link in the email opens the web reset page
     * rather than deep-linking back into the app: a token entry screen on the
     * handset would be a second implementation of the same flow, and the reset
     * has to work from a borrowed laptop anyway — the phone is often the thing
     * the person has lost access to.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();

        // Same rule as the web flow: a disabled account cannot let itself back
        // in, and the response never says whether the address exists.
        if ($user && $user->is_active !== false) {
            Password::sendResetLink(['email' => $data['email']]);
        }

        return $this->ok([
            'message' => __('api.reset_link_sent'),
        ]);
    }

    /** The signed-in user, for a client restoring a session on launch. */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Sign out this device only.
     *
     * The app should send the push token it registered with. Revoking the
     * access token stops this handset reading anything, but says nothing about
     * where notifications go — and a phone that keeps receiving somebody's
     * leave approvals after they signed out is a leak, not a loose end.
     */
    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate(['push_token' => 'nullable|string|max:255']);

        if (! empty($data['push_token'])) {
            $request->user()->pushDevices()->where('token', $data['push_token'])->delete();
        }

        $user = $request->user();
        $user->currentAccessToken()->delete();

        event(new Logout('sanctum', $user));

        return $this->ok(['message' => __('api.signed_out')]);
    }

    /** Sign out everywhere — for a lost or stolen phone. */
    public function logoutAll(Request $request): JsonResponse
    {
        $user  = $request->user();
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        // Every handset, not just the one asking: this is the endpoint for a
        // phone that is gone, and it must stop pushing to it as well.
        $devices = $user->pushDevices()->delete();

        // Recorded here rather than as a Logout event, because the event would
        // say only that somebody signed out — and the reach is the whole point
        // of this endpoint. An administrator reviewing a lost-phone report
        // needs to see that it was used and how far it went, not infer it from
        // tokens that are simply no longer there.
        ActivityLog::record(
            event: ActivityLog::LOGOUT,
            description: sprintf(
                'Signed out of every device from the mobile app — %d token(s) revoked, %d handset(s) unregistered',
                $count,
                (int) $devices,
            ),
            actor: $user,
            request: $request,
        );

        return $this->ok([
            'message'          => __('api.signed_out_all'),
            'tokens_revoked'   => $count,
            'devices_removed'  => (int) $devices,
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
     * Turn a sign-in away when the account is bound to a different handset
     * (B1.6), or null to carry on.
     *
     * The gap this closes is the oldest one in attendance: somebody hands a
     * colleague their password and the colleague clocks them in. Geofencing
     * does not touch it — the colleague is *at* the office — and the B2.7 flags
     * do not either, because nothing is being spoofed.
     *
     * **Off unless the company asks for it.** And when it is on, an app that
     * sends no `device_id` is let through rather than refused: that is an older
     * build, not an impostor, and locking out everybody mid-rollout is how a
     * security control gets switched back off for good. It cannot be bound
     * either, so it buys nothing — which is the honest trade and is why the
     * store listing, not this endpoint, is where an upgrade is pushed.
     *
     * The refusal is recorded on the security trail. It looks the same whether
     * it is a shared password or a stolen one, which is exactly why the message
     * names no accusation and points at the one person who can fix it.
     */
    protected function refuseUntrustedDevice(Request $request, User $user, array $data): ?JsonResponse
    {
        if (! $user->company?->policy('enforce_device_binding')) {
            return null;
        }

        $deviceId = $data['device_id'] ?? null;

        if ($deviceId === null || $deviceId === '') {
            return null;
        }

        $verdict = TrustedDevice::admit($user, $deviceId, [
            'device_name' => $data['device_name'] ?? null,
            'platform'    => $data['platform'] ?? null,
        ]);

        if ($verdict['allowed']) {
            if ($verdict['first']) {
                ActivityLog::record(
                    event: ActivityLog::LOGIN,
                    description: sprintf(
                        'Bound this account to a handset: %s',
                        $data['device_name'] ?? 'unnamed device',
                    ),
                    actor: $user,
                    actorLabel: $user->email,
                    request: $request,
                );
            }

            return null;
        }

        ActivityLog::record(
            event: ActivityLog::LOGIN_FAILED,
            description: sprintf(
                'Correct password from an untrusted handset (%s) — this account is bound to %s',
                $data['device_name'] ?? 'unnamed device',
                $verdict['device']?->device_name ?? 'another device',
            ),
            actor: $user,
            actorLabel: $user->email,
            request: $request,
        );

        return $this->fail('device_not_trusted', __('api.device_not_trusted'), 403);
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
