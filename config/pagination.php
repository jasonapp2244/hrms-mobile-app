<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How many rows a list shows at once
    |--------------------------------------------------------------------------
    |
    | There were twenty-three `paginate(n)` calls across the controllers and
    | five different values of n, and no rule anybody could state for which
    | list got which. It was drift: each list was written on a different day
    | and picked a number that looked right on that screen.
    |
    | The sizes below are the ones that were in use, kept rather than flattened
    | to a single number — a dense audit table and a card grid genuinely do not
    | want the same count, and collapsing them would be a visual change made
    | under cover of a refactor. What changes is that the number now has one
    | home and a reason written beside it.
    |
    | Call `$this->perPage('key')` from a controller. A key that is not listed
    | here falls back to the default rather than failing, so adding a list does
    | not require touching this file first.
    |
    */

    'web' => [

        'default' => (int) env('PAGINATION_PER_PAGE', 15),

        'lists' => [
            // Dense, single-line audit rows: more of them fit, and these are
            // read by scanning for one entry rather than by browsing.
            'activity_logs'   => 30,
            'crash_reports'   => 30,

            // Crashes grouped by fingerprint — fewer rows, each one a summary
            // of many occurrences rather than a single line.
            'crash_groups'    => 25,

            // Table rows carrying times and a status pill.
            'attendance'      => 25,
            'trusted_devices' => 25,

            // Rows with a body of text or a decision attached.
            'announcements'   => 20,
            'notifications'   => 20,
            'leave'           => 20,
            'regularisations' => 20,
            'shift_swaps'     => 20,

            // The employee's own leave list sits under other content on a
            // portal page rather than owning the screen.
            'leave_requests'  => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API paging
    |--------------------------------------------------------------------------
    |
    | `ApiController::pageMeta()` has always returned `per_page`, which told
    | every client there was a page size to know about — while no endpoint read
    | one from the request. The app could see the number and not change it.
    |
    | It is now a real parameter, and it is **clamped rather than validated**:
    | anything below 1 or unparseable takes the default, anything above the
    | maximum takes the maximum. A list endpoint that 422s because a client
    | asked for one row too many fails a person trying to see their own leave,
    | to protect a server that could have answered. The response says what it
    | actually used, so a client that asked for 500 can see it got 100.
    |
    | The ceiling is the point: without one, `?per_page=100000` is a way to ask
    | the server to build every row it owns into a single JSON document.
    |
    */

    'api' => [
        'default' => (int) env('API_PER_PAGE', 15),
        'max'     => (int) env('API_PER_PAGE_MAX', 100),

        // Per-endpoint defaults, for the lists whose natural page is not the
        // API default. These are what the endpoints already served before the
        // parameter existed, kept so that adding the control does not quietly
        // change what an app that sends nothing receives.
        'lists' => [
            // A staff directory is browsed by scrolling, not by paging, and a
            // company fits in a handful of requests at this size.
            'directory' => 30,

            // Deep notification history has no readers; the newest few do.
            'notifications' => 25,

            // HR's queue and register on the phone. Smaller than the
            // directory: each row carries dates, a balance and a decision,
            // and a screen of thirty of those is a wall rather than a list.
            'hr_leave'     => 20,
            'hr_employees' => 20,
        ],
    ],

];
