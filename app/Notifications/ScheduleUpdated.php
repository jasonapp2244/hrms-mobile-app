<?php

namespace App\Notifications;

use App\Notifications\Messages\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Your roster for these dates has been published or changed (A9.5).
 *
 * Sent to the employee, not to HR — they are the one who has to be somewhere at
 * a particular time, and a roster published on Friday for a Monday start is
 * only useful if they hear about it.
 *
 * One notification per person per publish, carrying the range and how many days
 * it covers. Per-day messages would mean seven notifications for a week, which
 * is the pattern that gets an app muted.
 *
 * This one does get a push. A schedule change is the second thing after a
 * missed checkout that people genuinely need to know away from a desk, and
 * plenty of staff have no work email at all.
 */
class ScheduleUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $from,
        public string $to,
        public int $days,
        public bool $isChange = false,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            'mail',
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    protected function headline(): string
    {
        return $this->isChange ? 'Your schedule has changed' : 'Your schedule is ready';
    }

    protected function summary(): string
    {
        return sprintf(
            '%d day(s) between %s and %s.',
            $this->days,
            \Carbon\Carbon::parse($this->from)->format('M j'),
            \Carbon\Carbon::parse($this->to)->format('M j'),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'schedule_updated',
            'title'   => $this->headline(),
            'message' => $this->summary(),
            'from'    => $this->from,
            'to'      => $this->to,
            'days'    => $this->days,
            'url'     => route('employee.dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->headline())
            ->greeting($this->headline())
            ->line($this->summary())
            ->action('View your schedule', route('employee.dashboard'))
            ->line('Check the times before your next shift — they may have moved.');
    }

    /** Tapping it opens the schedule tab rather than just the app. */
    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->headline(),
            body: $this->summary(),
            data: [
                'type'  => 'schedule_updated',
                'route' => 'schedule',
                'from'  => $this->from,
                'to'    => $this->to,
            ],
        );
    }
}
