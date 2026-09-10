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
            return __('notifications.document_expiring.timing.none');
        }

        $days = (int) now()->startOfDay()->diffInDays($expires->copy()->startOfDay(), absolute: true);

        if ($expires->isPast()) {
            return $days === 0
                ? __('notifications.document_expiring.timing.expired_today')
                : __('notifications.document_expiring.timing.expired_days', ['days' => $days]);
        }

        return $days === 0
            ? __('notifications.document_expiring.timing.expires_today')
            : __('notifications.document_expiring.timing.expires_days', ['days' => $days]);
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->document->employee;

        return [
            'type'      => 'document_expiring',
            'title'     => $this->document->hasExpired()
                ? __('notifications.document_expiring.title_expired')
                : __('notifications.document_expiring.title_expiring'),
            'message'   => __('notifications.document_expiring.body', [
                'employee' => $employee?->full_name ?? __('notifications.document_expiring.an_employee'),
                'title'    => $this->document->title,
                'type'     => $this->document->type_label,
                'timing'   => $this->timing(),
            ]),
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
            ->subject(__(
                $expired
                    ? 'notifications.document_expiring.subject_expired'
                    : 'notifications.document_expiring.subject_expiring',
                [
                    'title'    => $this->document->title,
                    'employee' => $employee?->full_name
                        ?? __('notifications.document_expiring.employee_word'),
                ],
            ))
            ->greeting(__($expired
                ? 'notifications.document_expiring.greeting_expired'
                : 'notifications.document_expiring.greeting_expiring'))
            ->line(__('notifications.document_expiring.line', [
                'title'    => $this->document->title,
                'type'     => $this->document->type_label,
                'employee' => $employee?->full_name
                    ?? __('notifications.document_expiring.an_employee_lower'),
                'timing'   => $this->timing(),
            ]))
            ->when($employee !== null, fn (MailMessage $mail) => $mail->action(
                __('notifications.document_expiring.action'),
                route('employees.documents.index', $employee),
            ))
            ->line(__('notifications.document_expiring.why'));
    }
}
