<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Who was late this morning (A9.3).
 *
 * A digest rather than one alert per person, and that is the whole design. An
 * office of two hundred produces a dozen late arrivals on a wet Monday; twelve
 * separate notifications is a morning nobody reads, and the pattern — who, how
 * many, how late — is the thing HR actually acts on. One message a day, with
 * the list in it.
 *
 * Sent after the grace period has passed for the shift that has started, so
 * somebody who is five minutes late and inside their grace never appears.
 */
class LateArrivalsDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, at: string, minutes: int, department: string}>  $arrivals
     */
    public function __construct(
        public array $arrivals,
        public string $workDate,
    ) {}

    public function via(object $notifiable): array
    {
        // No push. This is a thing to look at during the working day, and a
        // buzzing phone about somebody else's lateness helps nobody.
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $count = count($this->arrivals);

        return [
            'type'    => 'late_arrivals',
            'title'   => $count . ' late arrival' . ($count === 1 ? '' : 's'),
            'message' => $count === 1
                ? sprintf('%s clocked in %d minute(s) late.', $this->arrivals[0]['name'], $this->arrivals[0]['minutes'])
                : sprintf('%d people clocked in late on %s.', $count, $this->workDate),
            'work_date' => $this->workDate,
            'arrivals'  => $this->arrivals,
            'url'       => route('reports.late', ['from' => $this->workDate, 'to' => $this->workDate]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->arrivals);

        $mail = (new MailMessage())
            ->subject(sprintf('%d late arrival%s — %s', $count, $count === 1 ? '' : 's', $this->workDate))
            ->greeting('Late arrivals for ' . $this->workDate)
            ->line('These clock-ins were past the shift start and outside the grace period.');

        foreach ($this->arrivals as $arrival) {
            $mail->line(sprintf(
                '· %s (%s) — in at %s, %d minute(s) late',
                $arrival['name'],
                $arrival['department'],
                $arrival['at'],
                $arrival['minutes'],
            ));
        }

        return $mail
            ->action('Open the late arrivals report', route('reports.late', [
                'from' => $this->workDate, 'to' => $this->workDate,
            ]))
            ->line('You are receiving this because you hold the reporting permission.');
    }
}
