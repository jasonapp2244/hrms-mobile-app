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

    /** AM or PM for a 0–23 hour, in the caller's language. */
    public static function meridiem(int $hour): string
    {
        return $hour < 12 ? __('time.am') : __('time.pm');
    }
}
