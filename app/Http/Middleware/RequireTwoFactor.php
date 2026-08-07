<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold admin and HR at the setup screen until they have a second factor (A1.7).
 *
 * Only bites when the company has switched `require_two_factor_for_staff` on,
 * and only for the two roles that can read and change everybody's records.
 *
 * The setup routes, the profile, and signing out are all still reachable —
 * otherwise the policy would be a wall with the door on the wrong side of it.
 */
class RequireTwoFactor
{
    /** Reachable while somebody is being held: the way out, and the way through. */
    protected array $allowed = [
        'two-factor.show',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate',
        'logout',
        'profile.index',
        'profile.password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasTwoFactor() || ! $user->mustUseTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs(...$this->allowed)) {
            return $next($request);
        }

        // The mobile API carries its own token and has no screen to redirect
        // to; refusing with a reason is the only useful thing to say.
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'two_factor_required',
                'message' => 'Your company requires two-factor authentication on this account. Set it up in the web dashboard.',
            ], 403);
        }

        return redirect()->route('two-factor.show')->with('error',
            'Your company requires two-factor authentication on administrator and HR accounts. Set it up to carry on.');
    }
}
