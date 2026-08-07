<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign somebody out after a stretch of doing nothing (A1.9).
 *
 * Laravel's own session lifetime already expires an idle session, but it is a
 * server-wide number in a config file. What an HR system is actually asked for
 * is a policy the company sets — "our staff records are not to sit unlocked on
 * a shared machine for two hours" — so the limit is read off the company and is
 * zero, meaning off, until somebody sets it.
 *
 * Idle rather than absolute: the clock is reset on every request, so nobody is
 * thrown out in the middle of a long piece of work. It is inactivity that is
 * being timed, which is what "somebody walked away from the screen" looks like.
 *
 * The timeout is enforced here rather than trusted to the session lifetime
 * because the two answer different questions. The session lifetime decides when
 * the cookie stops being valid; this decides when the company says a person has
 * to prove who they are again, and it has to be able to be shorter.
 */
class EnforceIdleTimeout
{
    /** Where the last-seen timestamp lives in the session. */
    public const KEY = 'last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $minutes = $this->timeoutFor($request);

        if ($minutes <= 0) {
            return $next($request);
        }

        $last = $request->session()->get(self::KEY);

        if ($last && now()->diffInMinutes($last, absolute: true) >= $minutes) {
            $user = Auth::user();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Recorded so the trail can tell a timeout apart from somebody
            // deliberately signing out — they look identical afterwards, and
            // only one of them means the person is still at their desk.
            ActivityLog::record(
                event: ActivityLog::SESSION_EXPIRED,
                description: "Signed out after {$minutes} minutes of inactivity",
                actor: $user,
                request: $request,
            );

            // The API authenticates per token and has no session to time out;
            // returning a redirect there would hand a mobile client HTML.
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'unauthenticated',
                    'message' => 'Your session timed out. Please sign in again.',
                ], 401);
            }

            return redirect()->route('login')
                ->with('error', 'You were signed out after ' . $minutes . ' minutes of inactivity.');
        }

        $request->session()->put(self::KEY, now()->toDateTimeString());

        return $next($request);
    }

    /**
     * The signed-in user's company policy, or nothing.
     *
     * A user with no company — the very first administrator, before a company
     * exists — is never timed out. There is no policy to apply, and locking
     * somebody out of the screen where they would set one is a trap.
     */
    protected function timeoutFor(Request $request): int
    {
        $companyId = $request->user()?->company_id;

        if (! $companyId) {
            return 0;
        }

        return (int) (Company::find($companyId)?->policy('session_idle_timeout_minutes') ?? 0);
    }
}
