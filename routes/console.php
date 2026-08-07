<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Nothing below runs unless a scheduler is running. In production that is one
| cron entry:
|
|     * * * * * cd /path/to/hrms && php artisan schedule:run >> /dev/null 2>&1
|
| Locally, `php artisan schedule:work` does the same in the foreground. Queued
| email additionally needs `php artisan queue:work` — the notification bell
| deliberately does not.
|
| Each company applies its own timezone when deciding whether a shift has
| ended, so one run of each command serves all of them.
|
*/

// Quarter-hourly rather than at a fixed time: a company on rotating shifts has
// no single end of day — the night shift finishes when the morning one starts.
Schedule::command('attendance:remind-checkout')
    ->everyFifteenMinutes()
    // Two overlapping runs would send the same reminder twice.
    ->withoutOverlapping()
    ->runInBackground();

// Hourly for the same reason. The grace period inside the command decides what
// is actually due, so running often costs little and means a night shift is
// closed in the small hours rather than waiting for the next midnight.
Schedule::command('attendance:close-day')
    ->hourly()
    ->withoutOverlapping();

// Hourly, because "07:00" means a different instant in every timezone a company
// might be in, and the command works that out per company. The subscription's
// last_sent_at is what stops the other 23 runs sending anything.
Schedule::command('reports:send')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Late enough that the morning shift has arrived and the grace period has
// passed, early enough to still be the morning. One digest per company per day
// rather than an alert per person, which is a stream nobody reads.
Schedule::command('attendance:report-late')
    ->dailyAt('10:30')
    ->withoutOverlapping()
    ->onOneServer();

// Once a day is plenty — an expiry date moves once. Early enough that the mail
// is waiting when HR arrives, and the command only writes to somebody who has
// not already been told about that document.
Schedule::command('documents:check-expiry')
    ->dailyAt('06:45')
    ->withoutOverlapping()
    ->onOneServer();

// Daily, not monthly. Both halves are idempotent, so a daily run means a server
// that was down on the 1st of the month — or over New Year, which is when the
// year-end roll happens and when nobody is watching — catches up the next day
// rather than leaving everybody without a balance.
Schedule::command('leave:process')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

// Nightly, and verified rather than assumed. Runs at 02:10 — after the last
// hourly close has settled and well before anyone clocks in, so the dump is of
// a quiet database and the verification restore is not competing with traffic.
//
// onOneServer matters if this is ever load-balanced: two servers dumping the
// same database on the same schedule would rotate each other's backups away.
Schedule::command('db:backup --verify')
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
