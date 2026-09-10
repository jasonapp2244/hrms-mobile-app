<?php

namespace App\Support;

/**
 * Which languages this install answers in (C1.18).
 *
 * One list, because three things read it and they must not drift: the API
 * middleware that sets the locale per request, the column that remembers what a
 * person reads, and the test that checks every English key has a translation.
 *
 * It deliberately mirrors `AppLocale.supported` in the app. Adding a language
 * means a row here, a `lang/<code>/` directory, and an `.arb` file on the
 * handset — in that order, because the server can answer in a language the app
 * cannot draw, and the reverse would leave the app showing English sentences it
 * had no way to translate.
 */
class Locales
{
    /**
     * Supported languages, most preferred first.
     *
     * The first entry is the fallback, and is the language every string in the
     * codebase is written in.
     */
    public const SUPPORTED = ['en', 'es'];

    public static function default(): string
    {
        return self::SUPPORTED[0];
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    /**
     * The best language for an `Accept-Language` header.
     *
     * A deliberately forgiving parse. The header is a q-weighted list —
     * `es-419,es;q=0.9,en;q=0.8` — and browsers, HTTP clients and the app all
     * write it slightly differently. Anything unreadable answers with the
     * default rather than refusing: this is a preference, not a credential, and
     * a request that cannot be served in Spanish is still perfectly serviceable
     * in English.
     *
     * Region is dropped. `es-MX` and `es-419` are Spanish; a translation per
     * region is a different decision from a translation per language, and this
     * install has not made it.
     */
    public static function fromHeader(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return self::default();
        }

        $best    = self::default();
        $quality = -1.0;

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag  = strtolower(trim($bits[0]));

            if ($tag === '') {
                continue;
            }

            // A weight of exactly 0 means "not this one", which is the one case
            // where a named language is worse than saying nothing.
            $q = 1.0;
            foreach (array_slice($bits, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $m)) {
                    $q = (float) $m[1];
                }
            }

            if ($q <= 0) {
                continue;
            }

            $language = explode('-', $tag)[0];

            if ($language === '*') {
                continue;
            }

            if (self::isSupported($language) && $q > $quality) {
                $best    = $language;
                $quality = $q;
            }
        }

        return $best;
    }
}
