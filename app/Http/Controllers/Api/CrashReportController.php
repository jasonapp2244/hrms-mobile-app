<?php

namespace App\Http\Controllers\Api;

use App\Models\CrashReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Crashes the app did not survive, delivered on the next launch (B6.5).
 *
 * **Unauthenticated, on purpose.** The crash worth having is the one that stops
 * the app opening, and an endpoint behind `auth:sanctum` would collect every
 * crash except that one. A token is read if the request happens to carry one,
 * so a report from a signed-in handset is attributed; one from before sign-in
 * is kept anyway with no user against it.
 *
 * That makes it a public write, which is an abuse surface, so it is fenced on
 * every side rather than trusted: its own rate limiter, a hard cap on the batch
 * and on every field, and a body that is stored as strings and never
 * interpreted. Nothing here reads a stack trace as anything but text.
 */
class CrashReportController extends ApiController
{
    /** Reports accepted in one call. The app never queues more than this. */
    public const MAX_BATCH = 5;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reports'                 => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'reports.*.exception'     => ['required', 'string', 'max:191'],
            'reports.*.message'       => ['nullable', 'string', 'max:500'],
            'reports.*.stack'         => ['nullable', 'string', 'max:' . CrashReport::STACK_LIMIT],
            'reports.*.platform'      => ['nullable', 'string', 'max:16'],
            'reports.*.app_version'   => ['nullable', 'string', 'max:32'],
            'reports.*.os_version'    => ['nullable', 'string', 'max:191'],
            'reports.*.occurred_at'   => ['nullable', 'date'],
        ]);

        // Read rather than required. The route carries no auth middleware, so
        // this resolves a bearer token when there is one and null when there
        // is not — which is the whole point of the endpoint being public.
        $user = $request->user('sanctum');

        $stored = 0;

        foreach ($data['reports'] as $report) {
            // validate() omits absent nullable keys, so every one of these is
            // read with a fallback rather than by index.
            $exception = (string) $report['exception'];
            $stack     = $report['stack'] ?? null;

            CrashReport::create([
                'company_id'  => $user?->company_id,
                'user_id'     => $user?->id,
                'platform'    => $report['platform'] ?? null,
                'app_version' => $report['app_version'] ?? null,
                'os_version'  => $report['os_version'] ?? null,
                'exception'   => $exception,
                'message'     => $report['message'] ?? null,
                'stack'       => $stack,
                'fingerprint' => CrashReport::fingerprintFor($exception, $stack),
                'occurred_at' => $this->occurredAt($report['occurred_at'] ?? null),
            ]);

            $stored++;
        }

        return $this->ok(['stored' => $stored], 201);
    }

    /**
     * When the handset says it happened.
     *
     * The device clock is trusted here, as it is for an offline punch — a
     * report written at the moment of a crash and delivered three days later
     * is worth nothing stamped with its arrival. Unlike a punch, nothing is
     * calculated from this, so the only bound that matters is keeping a wrong
     * clock from sorting a report to the top of the screen for ever.
     */
    protected function occurredAt(?string $raw): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($raw === null) {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return $now;
        }

        if ($at->greaterThan($now) || $at->lessThan($now->subDays(30))) {
            return $now;
        }

        return $at;
    }
}
