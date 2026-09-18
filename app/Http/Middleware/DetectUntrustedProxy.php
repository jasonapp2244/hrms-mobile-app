<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notice when a proxy is forwarding to us and we are not trusting it.
 *
 * `bootstrap/app.php` records how this failed the first time: a guard that was
 * never true meant `TrustProxies` was never configured, so every punch behind
 * the proxy recorded the proxy's address — and "nothing failed, which is why it
 * survived". The fix landed; the *silence* did not. `TRUSTED_PROXIES` can still
 * be left unset on a box that sits behind Varnish, and the only symptom is an
 * `attendance_logs.ip_address` column that quietly agrees with itself on every
 * row. The audit trail (C1.10) and the IP column in exports (A7.9) are both
 * wrong, and nothing anywhere says so.
 *
 * `emp:preflight` did check the setting, but it could only ever say "unset",
 * which is a warning it had to show on every single-server install where unset
 * is exactly right. A check that cries wolf on the boxes that are fine and
 * shrugs at the one that is broken is worse than no check: it teaches the
 * reader to skip that line.
 *
 * So this supplies the missing half — the evidence. A forwarding header on a
 * real request, arriving while nothing is trusted, is proof that a proxy is in
 * front *and* that `$request->ip()` is currently returning its address rather
 * than the employee's. Preflight reads the marker and fails on it, instead of
 * guessing from configuration alone.
 *
 * **Cost is one `config()` read on a correctly configured box**, which is an
 * array lookup against cached config and nothing else — the first line returns
 * before touching the request. The cache write only happens where the fault is
 * real, and `Cache::add` makes it one write per TTL rather than one per
 * request, so a busy misconfigured box does not pay for the diagnosis on every
 * hit.
 *
 * Deliberately records and does not block. A punch with a wrong IP is still a
 * punch, and refusing it would turn a bad audit column into an app nobody can
 * clock in to.
 */
class DetectUntrustedProxy
{
    /** Where the evidence is left for `emp:preflight` to find. */
    public const CACHE_KEY = 'preflight:untrusted-proxy';

    /**
     * How long a sighting stands.
     *
     * Long enough that a deploy check run the morning after still sees last
     * night's traffic, short enough that fixing `TRUSTED_PROXIES` clears the
     * failure on its own rather than needing someone to know about a cache key.
     */
    public const REMEMBER_FOR_DAYS = 7;

    /**
     * The headers that mean "somebody forwarded this to you".
     *
     * `Forwarded` is the standardised one, the `X-Forwarded-*` pair is what is
     * actually deployed, and `Via` catches a cache like Varnish that adds
     * nothing else. Any one of them is enough — this is looking for the
     * presence of a hop, not parsing it.
     */
    private const FORWARDING_HEADERS = [
        'X-Forwarded-For',
        'Forwarded',
        'X-Forwarded-Proto',
        'Via',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Configured is configured: nothing to detect, and no cost to pay.
        // Read through config rather than env() for the reason spelled out in
        // Preflight — after `config:cache` the environment file is not loaded.
        if (! config('trustedproxy.proxies')) {
            $this->record($request);
        }

        return $next($request);
    }

    private function record(Request $request): void
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if (! $request->headers->has($header)) {
                continue;
            }

            // `Cache::add` writes only when the key is absent, so this is a
            // single round trip and at most one write per TTL.
            Cache::add(self::CACHE_KEY, [
                'header' => $header,
                // What the proxy says the client is, against what we are about
                // to store instead. Printing both is what makes the preflight
                // line self-evident rather than something to go and verify.
                'claimed' => $this->firstAddress($request->headers->get($header)),
                'recorded' => $request->ip(),
                'at' => now()->toIso8601String(),
            ], now()->addDays(self::REMEMBER_FOR_DAYS));

            return;
        }
    }

    /**
     * The left-most address in a forwarding header, which is the original
     * client where the header is a list.
     *
     * Best effort and deliberately not a parser: `Forwarded` and `Via` do not
     * carry a bare address at all, and the value is going into a diagnostic
     * line rather than into a decision. Null when there is nothing that looks
     * like one, and the preflight line reads fine without it.
     */
    private function firstAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $first = trim(explode(',', $value)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) ? $first : null;
    }
}
