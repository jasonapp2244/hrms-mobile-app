<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The timezones a company can be set to: the United States only.
 *
 * The company's zone decides what "09:00" means for every shift, what "today"
 * is for every report, and what the phone shows for every punch, so it has to
 * be easy to get right. It used to be a free-text box that wanted an exact IANA
 * identifier typed in — "America/New_York" — with nothing to say what that was.
 *
 * The client is in the United States and so is every office, so the list is
 * the US zones, by the names people use for them, and nothing else. Offering
 * the whole world put a zone on another continent one mis-scroll away from re-timing every
 * shift. A company already saved on some other zone keeps it on the form,
 * under its own heading, so opening the page and saving it changes nothing.
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

    /** Whether a zone is one the company form offers. */
    public static function isUs(?string $id): bool
    {
        return $id !== null && isset(self::US[$id]);
    }

    /**
     * Options for a select: ['United States' => [id => label]], plus
     * ['Current setting' => [...]] when $current is a real zone outside the US.
     *
     * Labels carry today's offset — "(UTC−04:00) Eastern Time" — so daylight
     * saving shows up as the number changing rather than as a surprise.
     */
    public static function grouped(?DateTimeImmutable $at = null, ?string $current = null): array
    {
        $at ??= new DateTimeImmutable('now');

        $groups = ['United States' => []];
        foreach (self::US as $id => $name) {
            $groups['United States'][$id] = self::offset($id, $at) . ' ' . $name;
        }

        if ($current && ! self::isUs($current) && in_array($current, DateTimeZone::listIdentifiers(), true)) {
            $groups['Current setting'] = [$current => self::offset($current, $at) . ' ' . str_replace('_', ' ', $current)];
        }

        return $groups;
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
