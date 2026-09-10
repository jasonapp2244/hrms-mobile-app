<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer in the language the caller asked for (C1.18).
 *
 * The app sends `Accept-Language` on every request — it has since B6.2 shipped,
 * which is the whole reason this can be added without touching a handset that
 * is already in somebody's pocket.
 *
 * **On the API group only.** The web dashboard stays English by decision: it is
 * HR's and the administrator's screen, and the workforce that needed Spanish is
 * the one holding the phone. Every message the two halves share — the geofence
 * refusal, a leave stage — is a `__()` call now, so a web request simply
 * resolves it under the default locale and comes out exactly as it did before.
 *
 * **Nothing here can refuse a request.** A header naming a language this build
 * has never heard of, a malformed one, or none at all all fall through to the
 * default. A 400 for an unreadable `Accept-Language` would be a client that
 * cannot talk to the server at all over a header it may not even have set
 * deliberately.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Locales::fromHeader($request->header('Accept-Language')));

        return $next($request);
    }

    /**
     * Remember what this person reads, for the things sent to them later.
     *
     * A notification is rendered when a **worker** picks it up, in a process
     * with no request and no header — and it is very often triggered by
     * somebody else entirely: HR approving leave in English decides what an
     * employee reads in Spanish. So the language cannot come from the request
     * that caused the message; it has to be a fact about the recipient.
     * `User::preferredLocale()` is where the framework looks, and this is what
     * fills it in.
     *
     * **In `terminate`, after the response has gone.** The header is what
     * decides this response; the column only matters for the next thing sent to
     * this person, so a write is not worth a millisecond of anybody's wait. It
     * writes only on a change, so the ordinary request costs one comparison.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $locale = Locales::fromHeader($request->header('Accept-Language'));

        if ($user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }
    }
}
