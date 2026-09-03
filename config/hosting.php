<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hosting mode
    |--------------------------------------------------------------------------
    |
    | 'vps'     — a server you have root on. systemd runs the queue worker as a
    |             daemon and cron fires the scheduler every minute. This is what
    |             Deployment-Guide_Production.md describes and is the default,
    |             because getting it wrong in this direction is loud: preflight
    |             complains that nothing is draining the queue.
    |
    | 'managed' — shared webspace (IONOS webspace, cPanel and the like). There
    |             is no systemd and no per-minute cron, so the scheduler runs on
    |             a 5-minute cron and the queue is drained by a second cron entry
    |             running `queue:work --stop-when-empty` instead of a daemon.
    |
    | This only changes how `emp:preflight` judges the install and what it tells
    | you to fix. It does not change what the application does at runtime — the
    | queue and the schedule behave identically either way.
    |
    */

    'mode' => env('HOSTING_MODE', 'vps'),

    /*
    |--------------------------------------------------------------------------
    | How long a job may sit before the queue counts as stalled
    |--------------------------------------------------------------------------
    |
    | Preflight does not ask whether a worker is configured — it asks whether
    | anything is actually draining the queue, by looking at how long the oldest
    | ready job has been waiting. The tolerance has to be larger than the gap
    | between drains, or a perfectly healthy install fails the check.
    |
    | On a daemon the gap is seconds, so 5 minutes is generous. On a cron-driven
    | queue the gap is the cron interval, and one skipped run must not fail a
    | deploy — hence 15 minutes, which absorbs two missed 5-minute runs.
    |
    | Seconds. Null means "pick the default for the mode above".
    |
    */

    'queue_max_wait' => env('HOSTING_QUEUE_MAX_WAIT'),

    /*
    |--------------------------------------------------------------------------
    | Cron interval on managed hosting
    |--------------------------------------------------------------------------
    |
    | Documented here so preflight can name the real number when it explains a
    | failure. IONOS webspace will not repeat a cron job more often than every
    | 5 minutes and kills any run that passes 60 seconds; every scheduled task in
    | routes/console.php already falls on a 5-minute boundary, so nothing in the
    | schedule had to change to fit.
    |
    */

    'managed_cron_minutes' => (int) env('HOSTING_CRON_MINUTES', 5),

];
