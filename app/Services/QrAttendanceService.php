<?php

namespace App\Services;

use App\Exceptions\QrRefused;
use App\Models\AttendanceQrToken;
use App\Models\Employee;
use App\Models\QrDisplay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QR check-in (A4.21): the office screen shows a code, the employee's own
 * signed-in phone scans it, and the punch is recorded.
 *
 * **Who** comes from the phone's token, **where** from the code — which only
 * the screen on the wall is showing — and **when** from the server. Which way
 * the punch goes is not decided here at all: `consume()` hands straight to
 * `AttendanceService::record()`, so lateness, the work date, the geofence and
 * the rule engine are exactly what they are for the button.
 *
 * **One code, one scan.** Each code is a row; the first scan claims it with a
 * conditional update, so two people scanning the same code in the same second
 * get one punch and one "scan again", never two punches or a shared one. The
 * screen then shows a fresh code within a second. A code nobody scans expires
 * after TOKEN_SECONDS, so a photograph of the screen is dead before it can be
 * sent anywhere.
 */
class QrAttendanceService
{
    /** How long a code shown on the screen stays scannable. */
    public const TOKEN_SECONDS = 30;

    /**
     * The screen swaps a code this close to expiry rather than showing it to
     * the end: somebody who lifts their phone with two seconds left would be
     * refused for no fault of their own.
     */
    public const REFRESH_MARGIN_SECONDS = 8;

    /** Leads every office code, so the app can refuse any other QR outright. */
    public const PREFIX = 'KEMP1';

    /** How long the screen shows "✓ Name — checked in" after a scan. */
    public const BANNER_SECONDS = 6;

    public function __construct(protected AttendanceService $attendance) {}

    /**
     * Whether this employee has to scan rather than tap.
     *
     * Only office staff. Somebody working from home has no screen to scan,
     * and a policy that refused them would simply stop them clocking in.
     */
    public function requiresQr(Employee $employee): bool
    {
        if (! $employee->company?->policy('require_qr_checkin')) {
            return false;
        }

        return ! in_array($employee->work_mode, ['wfh', 'hybrid'], true);
    }

    /**
     * What the screen should show right now.
     *
     * The screen polls and says which code it is showing. That code is kept
     * while it is unused and has time left; otherwise a new one is issued and
     * its value is sent. The value goes out only at that moment — the table
     * holds a hash — which is why the screen has to say what it is showing
     * rather than ask to be told.
     *
     * @return array{changed: bool, token_id: int, payload: string|null, expires_in: int}
     */
    public function currentFor(QrDisplay $display, ?int $showingId): array
    {
        $display->forceFill(['last_seen_at' => now()])->saveQuietly();

        if ($showingId !== null) {
            $showing = AttendanceQrToken::where('qr_display_id', $display->id)
                ->whereKey($showingId)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now()->addSeconds(self::REFRESH_MARGIN_SECONDS))
                ->first();

            if ($showing) {
                return [
                    'changed'    => false,
                    'token_id'   => $showing->id,
                    'payload'    => null,
                    'expires_in' => (int) now()->diffInSeconds($showing->expires_at, true),
                ];
            }
        }

        return ['changed' => true] + $this->issue($display);
    }

    /**
     * A new code for this screen.
     *
     * @return array{token_id: int, payload: string, expires_in: int}
     */
    public function issue(QrDisplay $display): array
    {
        // Unscanned codes are worth nothing once they expire, and a screen
        // makes a few thousand a day. Scanned ones are kept: they name the
        // screen a punch came from.
        AttendanceQrToken::where('qr_display_id', $display->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '<', now()->subHour())
            ->delete();

        $token = Str::random(32);

        $row = AttendanceQrToken::create([
            'company_id'    => $display->company_id,
            'office_id'     => $display->office_id,
            'qr_display_id' => $display->id,
            'token_hash'    => AttendanceQrToken::hash($token),
            'expires_at'    => now()->addSeconds(self::TOKEN_SECONDS),
        ]);

        return [
            'token_id'   => $row->id,
            'payload'    => $this->payload($display->office_id, $token),
            'expires_in' => self::TOKEN_SECONDS,
        ];
    }

    /** "KEMP1:{office}:{token}" — no personal data, nothing reusable. */
    public function payload(int $officeId, string $token): string
    {
        return self::PREFIX . ':' . $officeId . ':' . $token;
    }

    /** @return array{office_id: int, token: string}|null */
    public function parse(string $scanned): ?array
    {
        $parts = explode(':', trim($scanned));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[1]) || $parts[2] === '') {
            return null;
        }

        return ['office_id' => (int) $parts[1], 'token' => $parts[2]];
    }

    /**
     * Record the punch a scanned code stands for.
     *
     * One transaction for the claim and the punch: a punch refused by the
     * geofence rolls the claim back, so the code is still there for the person
     * standing at the screen once they have stepped inside.
     *
     * @param  array<string, mixed>  $meta  coordinates, IP and integrity flags
     * @return array{log: \App\Models\AttendanceLog, type: string, status: string}
     *
     * @throws QrRefused          when the code is not one this person may use
     * @throws \RuntimeException  when the geofence refuses the punch
     */
    public function consume(Employee $employee, string $scanned, array $meta = []): array
    {
        $parsed = $this->parse($scanned) ?? throw QrRefused::invalid();

        return DB::transaction(function () use ($employee, $parsed, $meta) {
            $token = AttendanceQrToken::with(['display', 'office'])
                ->where('token_hash', AttendanceQrToken::hash($parsed['token']))
                ->first();

            // Another company's code reads as no code at all: saying "that
            // belongs to somebody else" would confirm it was real.
            if (! $token
                || (int) $token->company_id !== (int) $employee->company_id
                || (int) $token->office_id !== $parsed['office_id']
                || ! $token->office?->is_active
                || ! $token->display
                || $token->display->isRevoked()) {
                throw QrRefused::invalid();
            }

            if ($token->consumed_at !== null) {
                throw QrRefused::alreadyUsed();
            }

            if ($token->expires_at->isPast()) {
                throw QrRefused::expired();
            }

            // The claim. Conditional, so of two scans racing for one code the
            // database lets exactly one through — the read above cannot promise
            // that on its own, and a lock would not on SQLite.
            $claimed = AttendanceQrToken::whereKey($token->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at'             => now(),
                    'consumed_by_employee_id' => $employee->id,
                ]);

            if ($claimed !== 1) {
                throw QrRefused::alreadyUsed();
            }

            // Against the office on the screen, not the one on the record:
            // where somebody actually scanned is the truthful answer, and a
            // cleaner covering another site is still at work.
            $result = $this->attendance->record($employee, $token->office, ['source' => 'qr'] + $meta);

            AttendanceQrToken::whereKey($token->id)->update(['attendance_log_id' => $result['log']->id]);

            return $result;
        });
    }

    /**
     * The scan the screen should acknowledge, if one happened in the last few
     * seconds — so the person walking away sees their own name.
     *
     * @return array{name: string, type: string, time: string}|null
     */
    public function lastScanFor(QrDisplay $display): ?array
    {
        $token = AttendanceQrToken::with(['employee', 'attendanceLog'])
            ->where('qr_display_id', $display->id)
            ->whereNotNull('attendance_log_id')
            ->where('consumed_at', '>=', now()->subSeconds(self::BANNER_SECONDS))
            ->latest('consumed_at')
            ->first();

        if (! $token?->attendanceLog) {
            return null;
        }

        return [
            'name' => $token->employee?->full_name ?? '',
            'type' => $token->attendanceLog->type,
            // scanned_at is the company's wall clock already (see
            // AttendanceService::record) — formatted, never shifted.
            'time' => \App\Support\Clock::time($token->attendanceLog->scanned_at),
        ];
    }
}
