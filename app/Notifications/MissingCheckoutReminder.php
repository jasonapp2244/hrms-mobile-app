<?php

namespace App\Notifications;

use App\Models\AttendanceLog;
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
        return ['database', 'mail'];
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
            'title' => 'You are still clocked in',
            'body'  => sprintf(
                'You clocked in at %s and no clock-out has been recorded. Tap to check out.',
                $this->openPunch->scanned_at->format('h:i A'),
            ),
            'work_date' => $this->workDate,
            'url'       => route('employee.dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are still clocked in')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line(sprintf(
                'You clocked in at %s on %s, and no clock-out has been recorded.',
                $this->openPunch->scanned_at->format('h:i A'),
                $this->openPunch->work_date->format('D j M Y'),
            ))
            ->line('If you have finished for the day, please clock out.')
            // Said plainly so nobody is surprised by the row appearing later.
            ->line('If nothing is recorded, the day will be closed automatically at your scheduled shift end.')
            ->action('Clock out', route('employee.dashboard'));
    }
}
