<?php

namespace App\Notifications;

use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A filed document is about to lapse, or already has (A3.8).
 *
 * Goes to HR rather than to the employee. Chasing a renewal is HR's job, and
 * the employee usually cannot see their own file anyway — telling them a
 * document they cannot reach is expiring would be an alert with no action
 * attached to it.
 *
 * No push channel. This is a thing to be dealt with during a working day, not
 * something worth buzzing a phone about.
 */
class DocumentExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /** A document deleted between the sweep and the send needs no chasing. */
    public $deleteWhenMissingModels = true;

    public function __construct(
        public EmployeeDocument $document,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** "Expired 3 days ago" or "expires in 12 days" — never a bare date. */
    protected function timing(): string
    {
        $expires = $this->document->expires_on;

        if (! $expires) {
            return 'has no expiry date';
        }

        $days = (int) now()->startOfDay()->diffInDays($expires->copy()->startOfDay(), absolute: true);

        if ($expires->isPast()) {
            return $days === 0 ? 'expired today' : "expired {$days} day(s) ago";
        }

        return $days === 0 ? 'expires today' : "expires in {$days} day(s)";
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->document->employee;

        return [
            'type'      => 'document_expiring',
            'title'     => $this->document->hasExpired() ? 'Document expired' : 'Document expiring',
            'message'   => sprintf(
                '%s — %s (%s) %s.',
                $employee?->full_name ?? 'An employee',
                $this->document->title,
                $this->document->type_label,
                $this->timing(),
            ),
            'document_id' => $this->document->id,
            'employee_id' => $this->document->employee_id,
            'expires_on'  => $this->document->expires_on?->toDateString(),
            'url'         => $employee ? route('employees.documents.index', $employee) : null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->document->employee;
        $expired = $this->document->hasExpired();

        return (new MailMessage())
            ->subject(($expired ? 'Expired: ' : 'Expiring soon: ')
                . $this->document->title . ' — ' . ($employee?->full_name ?? 'employee'))
            ->greeting($expired ? 'A document has expired' : 'A document is about to expire')
            ->line(sprintf(
                '%s (%s) for %s %s.',
                $this->document->title,
                $this->document->type_label,
                $employee?->full_name ?? 'an employee',
                $this->timing(),
            ))
            ->when($employee !== null, fn (MailMessage $mail) => $mail->action(
                'Open their documents',
                route('employees.documents.index', $employee),
            ))
            ->line('You are receiving this because you manage employee records.');
    }
}
