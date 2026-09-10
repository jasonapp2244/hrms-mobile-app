<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Messages\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What happened to the leave you asked for.
 *
 * Covers the middle step as well as the end. An employee who hears nothing
 * between submitting and a decision assumes it has been forgotten and chases —
 * telling them it reached HR costs one message and saves that.
 */
class LeaveRequestDecided extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * If the request is gone by the time a worker picks this up, say nothing.
     *
     * Without this a request removed between the decision and the send makes the
     * job fail permanently rather than quietly giving up. See LeaveRequestSubmitted.
     */
    public $deleteWhenMissingModels = true;

    /** A mail server that is briefly unreachable should not lose the message. */
    public $tries = 3;

    /** @param  string  $outcome  approved|rejected|cancelled|manager_approved */
    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $outcome,
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
     * The lock-screen version.
     *
     * The decision note is deliberately left out, even on a rejection. It can
     * run to a thousand characters, a notification truncates without saying so,
     * and half a reason for a refusal is worse than none — the app shows it
     * whole.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->title(),
            body: $this->body(),
            data: [
                'type'             => 'leave.' . $this->outcome,
                'leave_request_id' => $this->leaveRequest->id,
                'route'            => AppRoute::forType('leave.' . $this->outcome),
            ],
        );
    }

    /** As with the submitted notification: the bell now, the email when a worker runs. */
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
            'type'             => 'leave.' . $this->outcome,
            'title'            => $this->title(),
            'body'             => $this->body(),
            'leave_request_id' => $this->leaveRequest->id,
            'status'           => $this->leaveRequest->status,
            'url'              => route('employee.leave.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body());

        // The decision note is the whole substance of a rejection — without it
        // the employee has been told no and nothing else.
        if ($this->leaveRequest->decision_note) {
            $mail->line(__('notifications.leave_decided.note', [
                'note' => $this->leaveRequest->decision_note,
            ]));
        }

        return $mail
            ->action(__('notifications.leave_decided.action'), route('employee.leave.index'))
            ->line(__('notifications.leave_decided.dates', ['dates' => $this->dates()]));
    }

    /**
     * The outcomes this knows how to word.
     *
     * An outcome invented later falls to `default` rather than to a missing
     * translation key, which would print the key itself onto somebody's lock
     * screen.
     */
    protected const WORDED = ['approved', 'rejected', 'cancelled', 'manager_approved'];

    protected function title(): string
    {
        return __('notifications.leave_decided.title.' . $this->key());
    }

    protected function body(): string
    {
        return __('notifications.leave_decided.body.' . $this->key(), [
            'type'  => $this->leaveRequest->leaveType?->name,
            'dates' => $this->dates(),
        ]);
    }

    protected function key(): string
    {
        return in_array($this->outcome, self::WORDED, true) ? $this->outcome : 'default';
    }

    protected function dates(): string
    {
        $request = $this->leaveRequest;

        return $request->start_date->equalTo($request->end_date)
            ? $request->start_date->format('D j M Y')
            : sprintf('%s to %s', $request->start_date->format('j M'), $request->end_date->format('j M Y'));
    }
}
