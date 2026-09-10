<?php

namespace App\Http\Controllers\Api;

use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether this build of the app may carry on (B6.6).
 *
 * Unauthenticated, and the only endpoint that must answer during a maintenance
 * window: a gate reachable only with a token cannot tell somebody why their
 * sign-in is failing.
 *
 * **The server decides, the app obeys.** The comparison lives here rather than
 * in the app because the app is the half that cannot be fixed — a handset with
 * a broken comparator is one that has already shipped, and the only thing left
 * that can change its behaviour is the answer it is given.
 *
 * **It fails open.** A request with no version, an unreadable version, or a
 * platform the server has no link for is answered `ok`. Every alternative
 * stops an entire company clocking in, and the failure mode of a gate that
 * blocks by mistake is far worse than one that lets an old build through.
 */
class AppStatusController extends ApiController
{
    /** The app may carry on. */
    public const OK = 'ok';

    /** Too old to be talked to. The app must stop at an update screen. */
    public const UPDATE_REQUIRED = 'update_required';

    /** The server is deliberately not serving the app right now. */
    public const MAINTENANCE = 'maintenance';

    public function show(Request $request): JsonResponse
    {
        // Read rather than validated, deliberately. A 422 here would be the one
        // shape the app cannot act on: it asks this question before it knows
        // anything, including whether its own idea of the parameters is still
        // current, and a refusal would leave it with no verdict at all. Junk in
        // either field falls through to `ok`.
        // `?version[]=x` arrives as an array; anything that is not a plain
        // string is simply not a version.
        $version  = is_string($request->query('version')) ? $request->query('version') : null;
        $platform = is_string($request->query('platform')) ? $request->query('platform') : null;

        $storeUrl = match ($platform) {
            'android' => config('mobile.store_url.android'),
            'ios'     => config('mobile.store_url.ios'),
            default   => null,
        };

        // Maintenance outranks the version check: there is no point telling
        // somebody to update to a build that also cannot reach the server.
        if (config('mobile.maintenance')) {
            return $this->ok([
                'action'          => self::MAINTENANCE,
                'message'         => (string) config('mobile.maintenance_message'),
                'minimum_version' => null,
                'latest_version'  => null,
                'store_url'       => null,
            ]);
        }

        $minimum = config('mobile.minimum_version');

        // A minimum with no way to act on it would be a screen with a dead
        // button, so an unknown platform is told `ok` and left working.
        $tooOld = $storeUrl
            && AppVersion::isOlderThan($version, $minimum);

        return $this->ok([
            'action' => $tooOld ? self::UPDATE_REQUIRED : self::OK,
            'message' => $tooOld
                ? 'This version of the app is no longer supported. Update to carry on clocking in.'
                : null,
            'minimum_version' => $minimum ?: null,
            'latest_version'  => config('mobile.latest_version') ?: null,
            'store_url'       => $tooOld ? $storeUrl : null,
        ]);
    }
}
