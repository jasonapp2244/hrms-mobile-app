<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Setting two-factor authentication up, and turning it off (A1.7).
 *
 * The challenge at sign-in lives in LoginController; this is the enrolment side
 * and sits behind an ordinary authenticated session, on the profile screen.
 *
 * Nothing takes effect until a code is verified. `enable()` writes a secret and
 * shows it; only `confirm()` sets two_factor_confirmed_at, and only then does
 * the account start being challenged. Somebody who mistypes the key into their
 * authenticator finds out at that point, while still signed in, rather than at
 * the next sign-in when it is too late.
 */
class TwoFactorController extends Controller
{
    /** Where the freshly generated recovery codes are held for one page view. */
    public const CODES_KEY = 'two_factor_recovery_codes_shown';

    public function show(Request $request)
    {
        $user = $request->user();

        return view('profile.two-factor', [
            'user'      => $user,
            'secret'    => $user->two_factor_secret,
            'uri'       => $user->two_factor_secret
                ? Totp::provisioningUri(
                    config('app.name'),
                    $user->email,
                    $user->two_factor_secret,
                )
                : null,
            // Shown once, on the redirect straight after they are generated.
            // Held in the session rather than re-read from the user so that
            // reloading the page does not put them back on screen.
            'codes'     => $request->session()->pull(self::CODES_KEY),
        ]);
    }

    /** Start enrolment: generate a secret and show it for entry. */
    public function enable(Request $request)
    {
        $user = $request->user();

        // Regenerating while already confirmed would silently invalidate the
        // authenticator they are using without asking. Disable first.
        if ($user->hasTwoFactor()) {
            return back()->with('error', 'Two-factor is already on. Turn it off first if you want a new key.');
        }

        $user->forceFill([
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('two-factor.show');
    }

    /** Finish enrolment by proving a code can be produced from the secret. */
    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return redirect()->route('two-factor.show')
                ->with('error', 'Start again — there is no key waiting to be confirmed.');
        }

        if (! Totp::verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check your device clock is right, then try the next one.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $user->generateRecoveryCodes();

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: 'Two-factor authentication switched on',
            actor: $user,
        );

        return redirect()->route('two-factor.show')
            ->with(self::CODES_KEY, $codes)
            ->with('success', 'Two-factor is on. Save the recovery codes below — they are shown once.');
    }

    /**
     * Turn it off.
     *
     * Requires the current password. Otherwise anybody who found an unlocked
     * screen could remove the second factor in two clicks, which would make it
     * worth rather less than it looks.
     */
    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'That is not your current password.',
            ]);
        }

        if ($user->mustUseTwoFactor()) {
            return back()->with('error',
                'Your company requires two-factor on administrator and HR accounts, so it cannot be turned off here.');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: 'Two-factor authentication switched off',
            actor: $user,
        );

        return back()->with('success', 'Two-factor is off.');
    }

    /** Replace the recovery codes, e.g. after using or losing some. */
    public function regenerate(Request $request)
    {
        $user = $request->user();

        abort_unless($user->hasTwoFactor(), 404);

        $codes = $user->generateRecoveryCodes();

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: 'Two-factor recovery codes regenerated',
            actor: $user,
        );

        return redirect()->route('two-factor.show')
            ->with(self::CODES_KEY, $codes)
            ->with('success', 'New recovery codes. The old ones no longer work.');
    }
}
