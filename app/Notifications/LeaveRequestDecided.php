<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
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

    /** @param  string  $outcome  approved|rejected|cancelled|manager_approved */
    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $outcome,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->body());

        // The decision note is the whole substance of a rejection — without it
        // the employee has been told no and nothing else.
        if ($this->leaveRequest->decision_note) {
            $mail->line('Note: ' . $this->leaveRequest->decision_note);
        }

        return $mail
            ->action('View your leave', route('employee.leave.index'))
            ->line('Dates: ' . $this->dates());
    }

    protected function title(): string
    {
        return match ($this->outcome) {
            'approved'         => 'Your leave was approved',
            'rejected'         => 'Your leave request was declined',
            'cancelled'        => 'Your leave request was withdrawn',
            'manager_approved' => 'Your leave request has moved to HR',
            default            => 'Your leave request was updated',
        };
    }

    protected function body(): string
    {
        $type  = $this->leaveRequest->leaveType?->name;
        $dates = $this->dates();

        return match ($this->outcome) {
            'approved'         => "Your {$type} for {$dates} has been approved.",
            'rejected'         => "Your {$type} for {$dates} was not approved.",
            'cancelled'        => "Your {$type} for {$dates} has been withdrawn.",
            'manager_approved' => "Your manager approved {$type} for {$dates}. It is now with HR for the final decision.",
            default            => "Your {$type} for {$dates} was updated.",
        };
    }

    protected function dates(): string
    {
        $request = $this->leaveRequest;

        return $request->start_date->equalTo($request->end_date)
            ? $request->start_date->format('D j M Y')
            : sprintf('%s to %s', $request->start_date->format('j M'), $request->end_date->format('j M Y'));
    }
}
