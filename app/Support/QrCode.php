<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Throwable;

/**
 * A QR code, as inline SVG (A1.7).
 *
 * The one thing [Totp] deliberately does not do. Reed-Solomon over a Galois
 * field is not forty lines of obvious arithmetic the way HMAC-SHA1 is, and
 * hand-rolling it to avoid a dependency would have been the wrong trade twice
 * over — more code to own, and a class of bug that shows up as "my phone will
 * not scan this" rather than as a failing test.
 *
 * **SVG rather than PNG**, so nothing here depends on `ext-gd` being compiled
 * into whatever PHP the host happens to run. It also scales cleanly on the
 * high-density screen somebody is pointing at the page.
 *
 * **Inline rather than a URL.** The only thing this app puts in a QR is the
 * `otpauth://` URI, and that carries the TOTP secret. Served from its own route
 * it would be a second request carrying the secret — logged by the web server,
 * possibly cached by a proxy, and reachable by anybody who could guess the URL.
 * Written into the page it inherits exactly the protection the page already has.
 */
class QrCode
{
    /** Pixels along each edge. Big enough to scan across a desk. */
    public const SIZE = 220;

    /**
     * [$text] as an SVG document, or **null** when it cannot be encoded.
     *
     * Null rather than an exception: the QR is a convenience on a screen whose
     * real instruction is the typed setup key, and an encoder that fell over
     * must not take the page down with it. The caller draws the key either way.
     */
    public static function svg(string $text, int $size = self::SIZE): ?string
    {
        if ($text === '') {
            return null;
        }

        try {
            $writer = new Writer(
                new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd()),
            );

            return $writer->writeString($text);
        } catch (Throwable) {
            return null;
        }
    }
}
