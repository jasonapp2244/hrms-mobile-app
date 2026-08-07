<?php

namespace App\Support;

/**
 * Time-based one-time passwords, RFC 6238 (A1.7).
 *
 * Written here rather than pulled in as a package. The algorithm is a HMAC, a
 * truncation and a modulus — about forty lines — and every authenticator app
 * implements the same standard, so there is nothing to be gained from a
 * dependency and one less thing to keep patched. It is also completely testable
 * against the published vectors, which is done in TwoFactorTest.
 *
 * What is deliberately *not* here is a QR encoder. That is several hundred
 * lines of Reed-Solomon for a convenience, and every authenticator accepts a
 * setup key typed in by hand. The setup screen shows the key and the
 * `otpauth://` URI; when a QR library can be installed, rendering the URI as an
 * image is the only change needed.
 */
class Totp
{
    /** Seconds per code. Thirty is what every authenticator assumes. */
    public const PERIOD = 30;

    /** Digits in a code. Six, likewise. */
    public const DIGITS = 6;

    /**
     * How many steps either side of now are accepted.
     *
     * One, which is a 30-second grace in each direction. Zero rejects anybody
     * whose phone clock is a few seconds out or who typed slowly; more than one
     * widens the window an attacker has for a code they shoulder-surfed.
     */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh secret, as the base32 string the user will type in. */
    public static function generateSecret(int $bytes = 20): string
    {
        return static::base32Encode(random_bytes($bytes));
    }

    /**
     * Is this the code for that secret, right now?
     *
     * Compared with hash_equals rather than == so the comparison takes the same
     * time whatever the code is — a timing difference here leaks how much of a
     * guess was right, one digit at a time.
     */
    public static function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv($at ?? time(), self::PERIOD);

        for ($drift = -self::WINDOW; $drift <= self::WINDOW; $drift++) {
            if (hash_equals(static::codeAt($secret, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code for a given 30-second step. */
    public static function codeAt(string $secret, int $counter): string
    {
        $key = static::base32Decode($secret);

        // Big-endian 64-bit counter. 'J' would be machine-order; pack 'N' twice
        // gives network order on every platform, which is what the spec says.
        $binary = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);

        $hash = hash_hmac('sha1', $binary, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks where to
        // read four bytes from, and the top bit is masked off so the result is
        // positive on every platform.
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The otpauth:// URI an authenticator understands.
     *
     * The issuer appears twice — as a prefix on the label and as a parameter —
     * because older apps read one and newer ones read the other, and an account
     * that shows up as a bare email address among a dozen others is one nobody
     * can identify later.
     */
    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /** Base32 as RFC 4648, which is what the otpauth URI carries. */
    public static function base32Encode(string $binary): string
    {
        $bits = '';

        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /** The inverse. Spaces and padding are tolerated — people paste both. */
    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        // Trailing bits that do not make a whole byte are padding, and dropping
        // them is what the spec asks for.
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
