<?php

namespace App\Services;

use App\Models\Office;

/**
 * Dynamic rotating QR token engine.
 *
 * The office display (kiosk) shows a QR that encodes a short-lived token which
 * rotates every WINDOW_SECONDS. The token is an HMAC of the office's secret and
 * the current time-window index, so it cannot be forged without the secret and
 * expires almost immediately — blocking screenshot-and-clock-in-from-home abuse.
 */
class QrTokenService
{
    /** How often the QR rotates, in seconds. */
    public const WINDOW_SECONDS = 20;

    /** How many past windows are still accepted (tolerates scan/network lag). */
    public const GRACE_WINDOWS = 1;

    /**
     * Current time-window index (integer that increments every WINDOW_SECONDS).
     */
    public function currentWindow(?int $timestamp = null): int
    {
        $timestamp ??= time();
        return intdiv($timestamp, self::WINDOW_SECONDS);
    }

    /**
     * Compute the token for a given office and window index.
     */
    public function tokenFor(Office $office, int $window): string
    {
        $raw = hash_hmac('sha256', $office->id . '|' . $window, $office->qr_secret, true);
        // Short, URL-safe token
        return rtrim(strtr(base64_encode(substr($raw, 0, 12)), '+/', '-_'), '=');
    }

    /**
     * The token to display right now for an office.
     */
    public function currentToken(Office $office): string
    {
        return $this->tokenFor($office, $this->currentWindow());
    }

    /**
     * Seconds remaining until the current token rotates (for kiosk countdown).
     */
    public function secondsUntilRotate(?int $timestamp = null): int
    {
        $timestamp ??= time();
        return self::WINDOW_SECONDS - ($timestamp % self::WINDOW_SECONDS);
    }

    /**
     * The full payload string encoded into the QR image.
     * Format: "HRMS:{office_id}:{token}"
     */
    public function payload(Office $office): string
    {
        return sprintf('HRMS:%d:%s', $office->id, $this->currentToken($office));
    }

    /**
     * Validate a scanned token against the office, accepting the current window
     * plus GRACE_WINDOWS previous windows. Uses constant-time comparison.
     */
    public function validate(Office $office, string $token): bool
    {
        $current = $this->currentWindow();
        for ($i = 0; $i <= self::GRACE_WINDOWS; $i++) {
            $expected = $this->tokenFor($office, $current - $i);
            if (hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a scanned payload string "HRMS:{office_id}:{token}".
     * Returns ['office_id' => int, 'token' => string] or null if malformed.
     */
    public function parsePayload(string $payload): ?array
    {
        $parts = explode(':', trim($payload));
        if (count($parts) !== 3 || $parts[0] !== 'HRMS' || !ctype_digit($parts[1])) {
            return null;
        }
        return ['office_id' => (int) $parts[1], 'token' => $parts[2]];
    }
}
