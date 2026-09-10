<?php

namespace App\Support;

/**
 * Compares two mobile build numbers.
 *
 * Deliberately its own class, and deliberately free of the container, for the
 * same reason [SqlDumper] is: this decides whether a handset is allowed to talk
 * to the server at all, and it should be provable on its own with no framework
 * booted behind it.
 *
 * The format is Flutter's: `major.minor.patch`, optionally followed by `+build`.
 * Missing segments count as zero, so `1.2` and `1.2.0` are the same version.
 * The build number is ignored — the stores order releases by it, but it says
 * nothing about which API a build speaks, and two builds of one version differ
 * only in how they were packaged.
 */
class AppVersion
{
    /**
     * -1, 0 or 1, as `$a` is older than, the same as, or newer than `$b`.
     *
     * Null when either side cannot be read as a version. **Callers must treat
     * null as "do not judge", never as "older"** — the alternative is a
     * malformed string locking an entire company out of the app it clocks in
     * with.
     */
    public static function compare(?string $a, ?string $b): ?int
    {
        $left  = self::parse($a);
        $right = self::parse($b);

        if ($left === null || $right === null) {
            return null;
        }

        foreach ([0, 1, 2] as $i) {
            if ($left[$i] !== $right[$i]) {
                return $left[$i] < $right[$i] ? -1 : 1;
            }
        }

        return 0;
    }

    /** True when `$version` is readable and older than `$floor`. */
    public static function isOlderThan(?string $version, ?string $floor): bool
    {
        return self::compare($version, $floor) === -1;
    }

    /**
     * `[major, minor, patch]`, or null when the string is not a version.
     *
     * Strict on purpose: `v1.2.3`, `1.2.3-beta` and an empty string are all
     * unreadable rather than being coerced into something plausible. A guess
     * here is a guess about whether to lock somebody out.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    protected static function parse(?string $version): ?array
    {
        $version = trim((string) $version);

        if ($version === '') {
            return null;
        }

        // Drop the build number: "1.4.0+37" is the same version as "1.4.0".
        $version = explode('+', $version, 2)[0];

        if (! preg_match('/^\d+(\.\d+){0,2}$/', $version)) {
            return null;
        }

        $parts = array_map('intval', explode('.', $version));

        return [
            $parts[0],
            $parts[1] ?? 0,
            $parts[2] ?? 0,
        ];
    }
}
