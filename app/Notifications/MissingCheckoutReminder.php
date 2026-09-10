<?php

namespace App\Notifications;

use App\Models\AttendanceLog;
use App\Notifications\Messages\PushMessage;
use App\Support\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * You are still clocked in.
 *
 * Sent shortly after the shift ends, while there is still time to fix it — a
 * reminder the next morning is an apology, not a reminder.
 */
class MissingCheckoutReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * If the punch or the employee is gone by send time, say nothing.
     *
     * Attendance records are append-only now, so the punch itself will still be
     * there — but an employee removed between the sweep and the send would
     * otherwise fail this job for good. See LeaveRequestSubmitted.
     */
    public $deleteWhenMissingModels = true;

    /**
     * Fewer attempts than the leave notifications.
     *
     * This one is only useful while the shift is fresh in someone's mind. A
     * reminder that lands hours later, after a retry queue clears, is an apology
     * rather than a reminder — the same reason the command does not resend the
     * next day. `tries` is one of the few settings the framework does copy from a
     * notification onto its job; backoff is not, and lives on the job class.
     */
    public $tries = 2;

    public function __construct(
        public AttendanceLog $openPunch,
        public string $workDate,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            'mail',
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    /**
     * The one notification here that genuinely needs a handset.
     *
     * Somebody who forgot to clock out has left the building; an email they
     * read tomorrow is an apology rather than a reminder. The Android payload
     * is sent high priority for the same reason — waking the device is the
     * entire point.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: __('notifications.missing_checkout.title'),
            body: __('notifications.missing_checkout.push', [
                'time' => Clock::time($this->openPunch->scanned_at),
            ]),
            data: [
                'type'      => 'attendance.missing_checkout',
                'work_date' => $this->workDate,
                'route'     => AppRoute::forType('attendance.missing_checkout'),
            ],
        );
    }

    /** The bell now, the email when a worker runs. */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail'     => config('queue.default'),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'  => 'attendance.missing_checkout',
            'title' => __('notifications.missing_checkout.title'),
            'body'  => __('notifications.missing_checkout.body', [
                'time' => Clock::time($this->openPunch->scanned_at),
            ]),
            'work_date' => $this->workDate,
            'url'       => route('employee.dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.missing_checkout.title'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.missing_checkout.line', [
                'time' => Clock::time($this->openPunch->scanned_at),
                'date' => $this->openPunch->work_date->format('D j M Y'),
            ]))
            ->line(__('notifications.missing_checkout.finish'))
            // Said plainly so nobody is surprised by the row appearing later.
            ->line(__('notifications.missing_checkout.auto'))
            ->action(__('notifications.missing_checkout.action'), route('employee.dashboard'));
    }
}
