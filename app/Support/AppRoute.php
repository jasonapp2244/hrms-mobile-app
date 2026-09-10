<?php

namespace App\Support;

/**
 * Where in the mobile app a notification points.
 *
 * **One mapping, because there used to be two and they drifted.** Each
 * notification class named its own route inside `toPush()`, and the app's
 * `PushRoute` enum listed the ones it knew about; `schedule` was being sent for
 * months before the app had heard of it, so every roster notification arrived
 * and then landed nowhere in particular. Nothing failed, which is why it lasted.
 *
 * The notification history (B5.6) needs the same answer for a row written
 * before this class existed — `toDatabase()` never recorded a route — so the
 * mapping is keyed on the `type` both payloads already carry, rather than on
 * anything only a push knows.
 *
 * **Every value here must exist in `PushRoute` in the app.** A route the app
 * cannot parse opens it normally rather than crashing, so adding one here is
 * safe; it simply does nothing until the app catches up.
 */
class AppRoute
{
    /** Today's clock screen. */
    public const CLOCK = 'clock';

    /** The employee's own leave. */
    public const LEAVE = 'leave';

    /** The published roster. */
    public const SCHEDULE = 'schedule';

    /** A manager's approvals inbox. */
    public const APPROVALS = 'approvals';

    /**
     * The tab a notification of this `type` belongs to, or null when it has no
     * home in the app.
     *
     * Null is an ordinary answer, not a failure: `document_expiring` and
     * `late_arrivals` are addressed to HR, who work at a desk — the app has no
     * screen for either and inventing one to point at would be worse than
     * opening where the person already was.
     *
     * `announcement` (B5.5) is null for a different reason, and a deliberate
     * one: the body **is** the message, and the notification centre already
     * shows it in full. There is nowhere else to go, so it offers no button
     * rather than a button that lands somewhere unrelated.
     */
    public static function forType(?string $type): ?string
    {
        return match (true) {
            $type === null => null,

            // Every leave.* outcome — approved, rejected, cancelled,
            // manager_approved — lands on the employee's own leave screen.
            // The manager's copy is the one exception below.
            $type === 'leave.submitted' => self::APPROVALS,
            str_starts_with($type, 'leave.') => self::LEAVE,

            str_starts_with($type, 'attendance.') => self::CLOCK,
            $type === 'schedule_updated' => self::SCHEDULE,

            default => null,
        };
    }
}
