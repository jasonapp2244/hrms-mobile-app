<?php

namespace App\Notifications;

use App\Notifications\Messages\PushMessage;
use App\Support\AppRoute;
use App\Support\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Your shift starts shortly — clock in (B5.1).
 *
 * The mirror of {@see MissingCheckoutReminder}, and the only notification in
 * the system that arrives *before* the working day rather than during or after
 * it. That is the whole reason `checkin_reminder_before_minutes` can be set to
 * zero: a nudge at 05:50 for a six o'clock start is useful to most people and
 * an intrusion to some, and which one it is has to be the company's call.
 *
 * **No mail channel, deliberately.** Every other notification here has one,
 * because every other one is still worth reading an hour later. This one is
 * worth reading for ten minutes and then it is either unnecessary or an
 * accusation — and the workforce it is written for clocks in from a phone on a
 * doorstep, not from an inbox. The bell keeps the record; the push does the job.
 */
class ShiftStartingReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * If the employee is gone by send time, say nothing.
     *
     * @see LeaveRequestSubmitted
     */
    public $deleteWhenMissingModels = true;

    /**
     * One retry, and only because the first attempt might hit a cold queue.
     *
     * Shorter-lived than anything else here: this reminder is useful for the
     * minutes before a shift and misleading afterwards. A retry queue that
     * clears at 09:30 would tell somebody already at work to hurry up.
     */
    public $tries = 2;

    /**
     * @param  string  $workDate  The roster date this covers, 'Y-m-d'.
     * @param  \Carbon\CarbonInterface  $startsAt  Wall-clock shift start, in the company's timezone.
     */
    public function __construct(
        public string $workDate,
        public \Carbon\CarbonInterface $startsAt,
    ) {}

    /**
     * The bell and the handset, and nothing else.
     *
     * Falls back to the bell alone when FCM is not configured, which is the
     * same shape every other notification here takes — silent rather than
     * broken on an install that has no Firebase project yet.
     */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    /** Written as the request finishes; there is no mail leg to queue. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: __('notifications.shift_starting.title'),
            body: __('notifications.shift_starting.push', [
                'time' => Clock::time($this->startsAt),
            ]),
            data: [
                'type'      => 'attendance.shift_starting',
                'work_date' => $this->workDate,
                'route'     => AppRoute::forType('attendance.shift_starting'),
            ],
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'  => 'attendance.shift_starting',
            'title' => __('notifications.shift_starting.title'),
            'body'  => __('notifications.shift_starting.body', [
                'time' => Clock::time($this->startsAt),
            ]),
            'work_date' => $this->workDate,
            'url'       => route('employee.dashboard'),
        ];
    }
}
