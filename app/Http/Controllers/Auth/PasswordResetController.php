<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Forgot password, and the reset that follows it.
 *
 * Before this, a locked-out account could only be recovered by an administrator
 * editing it — which does not work for the one account that matters most, the
 * only administrator.
 */
class PasswordResetController extends Controller
{
    /**
     * The answer to every reset request, whatever actually happened.
     *
     * The address may not exist, may belong to a deactivated employee, or may
     * have asked a minute ago and been throttled. All three say this. Anything
     * else turns the form into a way of asking "does this person work here?",
     * and for an HR system the staff list is exactly what an attacker wants.
     */
    private const SENT = 'If that email address has an account, a reset link is on its way. Check your inbox, including spam.';

    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $data = $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Enter the email address you sign in with.'],
        );

        $user = User::where('email', $data['email'])->first();

        // A deactivated account must not be able to let itself back in. The
        // login screen already refuses one; without this check, reset would be
        // the way around that.
        if ($user && ($user->is_active ?? true)) {
            // The return value is deliberately dropped. It distinguishes "sent"
            // from "throttled", and acting on that difference would leak the
            // same thing the generic message exists to hide.
            Password::sendResetLink(['email' => $data['email']]);
        }

        return back()->with('status', self::SENT);
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                // Invalidates the "remember me" cookie on every browser that
                // has one. Without it, a stolen laptop stays signed in through
                // the reset that was meant to shut it out.
                'remember_token' => Str::random(60),
            ])->save();

            // The mobile app holds Sanctum tokens that outlive any web session,
            // and the whole point of a reset is often that somebody else has
            // the account. Revoking here is what makes it mean something.
            $user->tokens()->delete();

            // And stop pushing to handsets that are no longer trusted —
            // otherwise the previous holder keeps seeing leave decisions.
            $user->pushDevices()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Unlike the request step, being specific here leaks nothing: the
            // person already holds a token for this address. An expired link is
            // the common case by far, and "invalid token" sends people hunting
            // for a typo that is not there.
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $this->failureMessage($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with it below.');
    }

    private function failureMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'That reset link has expired or has already been used. Request a new one.',
            Password::INVALID_USER  => 'That reset link is not valid for this account.',
            Password::RESET_THROTTLED => 'You have tried that too many times. Wait a minute and try again.',
            default => 'That reset link could not be used. Request a new one.',
        };
    }
}
