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
