<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The oldest app build this server will talk to
    |--------------------------------------------------------------------------
    |
    | Read by GET /api/v1/app/status (B6.6). An app older than this is told to
    | update and stops at a screen with a store link rather than carrying on
    | against an API it no longer agrees with.
    |
    | **Empty means no floor**, and that is the default on purpose. A version
    | typed in here wrongly locks every handset in the company out at once, and
    | the people it locks out are the ones who clock in with it — so this stays
    | unset until somebody has a specific reason, and is raised only after the
    | replacement build is actually live in both stores.
    |
    | Compared on major.minor.patch. Anything after a '+' is a build number and
    | is ignored: the stores order builds by it, but it says nothing about which
    | API a build speaks.
    |
    */

    'minimum_version' => env('MOBILE_MIN_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | The newest build in the stores
    |--------------------------------------------------------------------------
    |
    | Advisory. Returned alongside the verdict so that a future "there is an
    | update" nudge has somewhere to read it from, and so support can see at a
    | glance what a handset should be running. Nothing is blocked by it, and the
    | app does not read it today.
    |
    */

    'latest_version' => env('MOBILE_LATEST_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode, for the app only
    |--------------------------------------------------------------------------
    |
    | Not `php artisan down`. That returns 503 to everything, which the app
    | cannot tell apart from an outage — so it would fall back to the offline
    | cache and let somebody queue punches into a server that is being migrated
    | underneath them. This flag lets the API keep answering while the app shows
    | a screen that says what is happening and when to come back.
    |
    | It lives in config rather than in the database because the moment it is
    | most needed is the moment the database is unavailable.
    |
    | `emp:preflight` fails a deploy that still has this on.
    |
    */

    'maintenance' => (bool) env('MOBILE_MAINTENANCE', false),

    // `?:` rather than an env() default: `MOBILE_MAINTENANCE_MESSAGE=` with
    // nothing after it reads as an empty string, not as absent, and would put a
    // blank screen in front of everybody at the worst possible moment.
    'maintenance_message' => env('MOBILE_MAINTENANCE_MESSAGE')
        ?: 'The app is briefly unavailable while we carry out maintenance. '
            . 'Please try again shortly, and tell your manager if you need to '
            . 'record attendance in the meantime.',

    /*
    |--------------------------------------------------------------------------
    | Where to send somebody who has to update
    |--------------------------------------------------------------------------
    |
    | An update screen with no working button is a dead end, so preflight
    | refuses a minimum version with no store link for both platforms.
    |
    */

    'store_url' => [
        'android' => env('MOBILE_STORE_URL_ANDROID'),
        'ios'     => env('MOBILE_STORE_URL_IOS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | How long crash reports are kept
    |--------------------------------------------------------------------------
    |
    | `crashes:prune` runs nightly. `crash_reports` is fed by an
    | unauthenticated endpoint, which makes it the one table that can grow
    | without anybody signing in to grow it — not because that is a way in
    | (the endpoint is rate-limited and every field is capped) but because a
    | server nobody watches would otherwise keep a year of stack traces for a
    | bug fixed in March.
    |
    | Safe to delete on a timer in a way the audit trails beside it are not: a
    | crash report is diagnosis, nothing is calculated from one, and nobody is
    | accountable to one. Set to 0 to keep everything.
    |
    */

    'crash_retention_days' => (int) env('MOBILE_CRASH_RETENTION_DAYS', 90),

];
