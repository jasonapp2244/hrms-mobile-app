<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * One way to write a time of day (C1.18).
 *
 * The server sends punch times **pre-formatted**, because the reading that
 * matters is the wall clock in the company's zone rather than a moment the
 * handset would re-render in its own — a punch made at 18:00 in New York must
 * not read as 23:00 to a phone on London time. That means the meridiem is the
 * server's to translate, and this is where it happens.
 *
 * `format('h:i A')` was written out at nine call sites. All of them go through
 * here now: under the default locale the output is character-for-character what
 * it always was, and there is one place to change when a third language wants
 * a 24-hour clock instead.
 */
class Clock
{
    /** "04:57 PM", or "04:57 p. m." — a zero-padded twelve-hour reading. */
    public static function time(CarbonInterface $at): string
    {
        return $at->format('h:i') . ' ' . self::meridiem((int) $at->format('G'));
    }

    /**
     * A real UTC moment, restated on the signed-in company's clock, for display.
     *
     * **Only for columns that hold UTC** — created_at, approved_at, voided_at,
     * published_at and the rest written with now(). Never for scanned_at or
     * work_date: punches are stored as the company's wall clock already, and
     * converting them again is how the manager panel printed every punch four
     * hours early.
     */
    public static function local(?CarbonInterface $utc): ?CarbonInterface
    {
        return $utc?->copy()->setTimezone(self::zone());
    }

    /** The signed-in user's company zone, or the app's when there is none. */
    public static function zone(): string
    {
        return auth()->user()?->company?->tz() ?? config('app.timezone');
    }

    /** AM or PM for a 0–23 hour, in the caller's language. */
    /**
     * A length of time as "7h 45m".
     *
     * Hours are how worked time, overtime and breaks are all discussed and
     * paid. The format was written out by hand in `ReportService` and was about
     * to be written out again by the attendance history screens, so it lives
     * here beside the one way to write a time of day.
     *
     * Negative minutes clamp to zero rather than rendering "-1h 59m". Every
     * caller is showing a duration that cannot be negative, so a negative one
     * is a bug upstream and printing it sideways only hides where.
     */
    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    public static function meridiem(int $hour): string
    {
        return $hour < 12 ? __('time.am') : __('time.pm');
    }
}
