<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Messages\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody on your team has asked for time off.
 *
 * Goes to whoever the request is waiting on — the line manager, or everyone who
 * can decide once it has reached HR.
 */
class LeaveRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * If the request is gone by the time a worker picks this up, say nothing.
     *
     * A queued notification stores the model as an id and reloads it on the way
     * out. Without this, a request withdrawn or removed in the meantime makes the
     * job throw ModelNotFoundException and fail for good, filling failed_jobs with
     * entries that read like a broken mail pipeline when nothing is broken. The
     * notification is about the request; no request, nothing worth sending.
     */
    public $deleteWhenMissingModels = true;

    /** A mail server that is briefly unreachable should not lose the message. */
    public $tries = 3;

    public function __construct(
        public LeaveRequest $leaveRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            'mail',
            // Only when push is actually configured. Listing the channel on an
            // install with no credentials would queue work per notification
            // that can never do anything.
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    /**
     * The lock-screen version.
     *
     * Names the person and the dates and stops. A manager glancing at a phone
     * needs to know a decision is waiting and whose it is; the reason, the
     * clashes and the buttons are one tap away in the app.
     */
    public function toPush(object $notifiable): PushMessage
    {
        $request = $this->leaveRequest;

        return new PushMessage(
            title: sprintf('%s requested leave', $request->employee?->full_name),
            body: sprintf(
                '%s, %s to %s.',
                $request->leaveType?->name,
                $request->start_date->format('j M'),
                $request->end_date->format('j M'),
            ),
            data: [
                'type'             => 'leave.submitted',
                'leave_request_id' => $request->id,
                // Tells the app which screen to open when it is tapped.
                'route' => 'approvals',
            ],
        );
    }

    /**
     * The bell is written straight away; only the email waits for a worker.
     *
     * Queueing both would mean the notification count does not move until
     * somebody runs `queue:work`, which on a site without a worker configured
     * looks exactly like the feature not working. Writing one row is cheap
     * enough to do in the request; talking to a mail server is not.
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail'     => config('queue.default'),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        $request = $this->leaveRequest;

        return [
            'type'         => 'leave.submitted',
            'title'        => sprintf('%s requested leave', $request->employee?->full_name),
            'body'         => sprintf(
                '%s day(s) of %s, %s to %s.',
                rtrim(rtrim(number_format((float) $request->days, 1), '0'), '.'),
                $request->leaveType?->name,
                $request->start_date->format('M j'),
                $request->end_date->format('M j, Y'),
            ),
            'leave_request_id' => $request->id,
            'employee_id'      => $request->employee_id,
            'employee'         => $request->employee?->full_name,
            'stage'            => $request->stage_label,
            // Where the bell should take someone who taps it. The approvals
            // inbox rather than the record itself: the point of the message is
            // that a decision is waiting.
            'url' => route('employee.approvals.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->leaveRequest;

        return (new MailMessage)
            ->subject(sprintf('Leave request from %s', $request->employee?->full_name))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line(sprintf(
                '%s has requested %s day(s) of %s.',
                $request->employee?->full_name,
                rtrim(rtrim(number_format((float) $request->days, 1), '0'), '.'),
                $request->leaveType?->name,
            ))
            ->line(sprintf(
                'Dates: %s to %s.',
                $request->start_date->format('D j M Y'),
                $request->end_date->format('D j M Y'),
            ))
            ->when((bool) $request->reason, fn (MailMessage $mail) => $mail->line('Reason: ' . $request->reason))
            ->action('Review the request', route('employee.approvals.index'))
            ->line('You are receiving this because the request is waiting on you.');
    }
}
