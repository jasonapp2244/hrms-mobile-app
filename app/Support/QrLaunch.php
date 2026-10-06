<?php

namespace App\Support;

use App\Models\Office;
use App\Models\QrDisplay;

/**
 * What the "Open QR screen" picker needs: every active office, and the screen
 * that opening it would reuse (A4.21).
 *
 * One answer for both places the picker appears — the dashboard and the QR
 * Screens page — and for the controller that acts on it, so the screen the
 * picker shows as "showing codes now" is the screen Open actually reuses.
 */
class QrLaunch
{
    /** A screen counts as showing codes when it fetched one within this long. */
    public const LIVE_SECONDS = 60;

    /**
     * The screen opening this office reuses: the active one seen most
     * recently, else the newest active one. Null when it has none, in which
     * case opening it creates one.
     */
    public static function screenFor(int $companyId, int $officeId): ?QrDisplay
    {
        return QrDisplay::where('company_id', $companyId)
            ->where('office_id', $officeId)
            ->active()
            ->orderByRaw('last_seen_at IS NULL')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    public static function isLive(?QrDisplay $screen): bool
    {
        return (bool) $screen?->last_seen_at?->gt(now()->subSeconds(self::LIVE_SECONDS));
    }

    /** The name a screen made by the picker is given. */
    public static function defaultName(Office $office): string
    {
        return $office->name . ' check-in screen';
    }

    /**
     * @return array{offices: array<int, array{id: int, name: string, url: ?string, live: bool, seen: ?string}>, localOnly: bool}
     */
    public static function forCompany(int $companyId): array
    {
        $offices = Office::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        return [
            'offices' => $offices->map(function (Office $office) use ($companyId) {
                $screen = self::screenFor($companyId, $office->id);

                return [
                    'id'   => $office->id,
                    'name' => $office->name,
                    'url'  => $screen?->url(),
                    'live' => self::isLive($screen),
                    // Relative, so the strip reads the same in every timezone.
                    'seen' => $screen?->last_seen_at?->diffForHumans(),
                ];
            })->all(),
            // A link on this computer's loopback address opens nowhere else —
            // the office tablet cannot reach it, however correct it is.
            'localOnly' => in_array(request()->getHost(), ['127.0.0.1', 'localhost', '::1', '[::1]'], true),
        ];
    }
}
