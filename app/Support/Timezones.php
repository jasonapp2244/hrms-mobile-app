<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The timezones a company can be set to, US first.
 *
 * The company's zone decides what "09:00" means for every shift, what "today"
 * is for every report, and what the phone shows for every punch, so it has to
 * be easy to get right. It used to be a free-text box that wanted an exact IANA
 * identifier typed in — "America/New_York" — with nothing to say what that was.
 *
 * The client is in the United States, so the US zones come first and by the
 * names people use for them. Every other identifier stays available below
 * them: a company with a branch abroad is still a company this has to serve.
 */
class Timezones
{
    /** What a fresh install proposes. The client is on the US east coast. */
    public const DEFAULT = 'America/New_York';

    /**
     * The US zones, west to east would read oddly on a form — Eastern first,
     * because that is where most people are.
     *
     * Phoenix is listed on its own because Arizona does not change its clocks:
     * picking "Mountain" for an Arizona office puts every shift an hour out for
     * half the year.
     */
    public const US = [
        'America/New_York'    => 'Eastern Time',
        'America/Chicago'     => 'Central Time',
        'America/Denver'      => 'Mountain Time',
        'America/Phoenix'     => 'Mountain Time — Arizona (no daylight saving)',
        'America/Los_Angeles' => 'Pacific Time',
        'America/Anchorage'   => 'Alaska Time',
        'Pacific/Honolulu'    => 'Hawaii Time',
        'America/Puerto_Rico' => 'Atlantic Time — Puerto Rico',
    ];

    /**
     * Options for a select: ['United States' => [id => label], 'All timezones' => [...]].
     *
     * Labels carry today's offset — "(UTC−04:00) Eastern Time" — so daylight
     * saving shows up as the number changing rather than as a surprise.
     */
    public static function grouped(?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');

        $us = [];
        foreach (self::US as $id => $name) {
            $us[$id] = self::offset($id, $at) . ' ' . $name;
        }

        $all = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            if (! isset(self::US[$id])) {
                $all[$id] = self::offset($id, $at) . ' ' . str_replace('_', ' ', $id);
            }
        }

        return ['United States' => $us, 'All timezones' => $all];
    }

    /** "(UTC−04:00)", with a real minus sign, for the given moment. */
    public static function offset(string $id, DateTimeImmutable $at): string
    {
        $seconds = (new DateTimeZone($id))->getOffset($at);
        $sign = $seconds < 0 ? '−' : '+';
        $seconds = abs($seconds);

        return sprintf('(UTC%s%02d:%02d)', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
