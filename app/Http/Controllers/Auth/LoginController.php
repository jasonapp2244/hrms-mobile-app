<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\QuickLogin;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /** Where the half-authenticated user waits between password and code. */
    public const PENDING_KEY = 'two_factor_pending_user';
    public const PENDING_REMEMBER_KEY = 'two_factor_pending_remember';

    public function show()
    {
        if (Auth::check()) {
            return redirect()->route(Auth::user()->homeRoute());
        }
        // Empty unless the demo panel is switched on — and it cannot be in
        // production. See App\Support\QuickLogin.
        return view('auth.login', ['quickLogins' => QuickLogin::accounts()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        // Only known roles may sign in: admin/HR reach the dashboard, employees
        // and managers reach the self-service portal. Any other account is rejected.
        if (! $user->hasAnyRole(['admin', 'hr', 'employee', 'manager'])) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'This account is not permitted to sign in.',
            ]);
        }

        if (! ($user->is_active ?? true)) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Contact your administrator.',
            ]);
        }

        // Second factor (A1.7). The password alone has not signed anybody in
        // yet: the session is dropped and the user id parked until a code
        // arrives, so a stolen password on its own reaches nothing — not the
        // dashboard, and not any authenticated route in between.
        if ($user->hasTwoFactor()) {
            $remember = $request->boolean('remember');

            Auth::logout();

            $request->session()->put(self::PENDING_KEY, $user->id);
            $request->session()->put(self::PENDING_REMEMBER_KEY, $remember);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        return $this->landing($user);
    }

    /** The "enter your code" screen. */
    public function challenge(Request $request)
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Check the code and finish signing in.
     *
     * Accepts either a live authenticator code or one of the recovery codes,
     * which is the whole point of the recovery codes: the failure this has to
     * survive is a lost or wiped phone, and by then the authenticator is gone.
     */
    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $code = trim($request->input('code'));
        $viaRecovery = false;

        if (! Totp::verify($user->two_factor_secret, $code)) {
            if (! $user->consumeRecoveryCode($code)) {
                ActivityLog::record(
                    event: ActivityLog::LOGIN_FAILED,
                    description: 'Password accepted, second factor refused',
                    actor: $user,
                    actorLabel: $user->email,
                );

                throw ValidationException::withMessages([
                    'code' => 'That code is not right. Codes change every 30 seconds — try the next one.',
                ]);
            }

            $viaRecovery = true;
        }

        // Read before forgetting — the other order silently drops "remember me"
        // for everybody with a second factor.
        $remember = (bool) $request->session()->pull(self::PENDING_REMEMBER_KEY, false);
        $request->session()->forget(self::PENDING_KEY);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if ($viaRecovery) {
            $left = count($user->fresh()->two_factor_recovery_codes ?? []);

            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Signed in with a recovery code — {$left} left",
                actor: $user,
            );

            return $this->landing($user)->with('error',
                "You used a recovery code. {$left} remain — generate a new set from your profile.");
        }

        return $this->landing($user);
    }

    /**
     * The user waiting on a code, if the session still holds one.
     *
     * Re-read from the database rather than kept in the session: an account
     * deactivated between the password and the code must not get in on the
     * strength of a session written a minute ago.
     */
    protected function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_KEY);

        if (! $id) {
            return null;
        }

        $user = User::find($id);

        return $user && ($user->is_active ?? true) && $user->hasTwoFactor() ? $user : null;
    }

    /**
     * Employees always land on their own portal; staff use intended() so deep
     * links still work after a session timeout.
     */
    protected function landing(User $user)
    {
        return $user->homeRoute() === 'employee.dashboard'
            ? redirect()->route('employee.dashboard')
            : redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
