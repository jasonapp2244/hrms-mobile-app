<?php

namespace App\Notifications;

use App\Models\ActivationCode;
use App\Support\QrCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The welcome email a new member of staff gets when HR creates their sign-in
 * (A4.21).
 *
 * The client asked for "a QR code emailed when the employee is created". What
 * the code does is the decision that matters: **it signs a phone in, once**,
 * and it can never record a punch. A code in an inbox can be forwarded or
 * screenshotted, and one that clocked somebody in would be exactly the
 * buddy-punch the office screen exists to stop. Attendance is always the
 * rotating code on the office screen, scanned by the person's own phone.
 *
 * Mail only, like the password reset: somebody without the app yet has nowhere
 * else to read it.
 *
 * **No password in it, ever.** HR is shown the generated one on screen to hand
 * over; the email points at "forgot password" for choosing their own, which
 * works whenever they read it — a reset token minted now would be dead within
 * the hour, and welcome emails are read days later.
 */
class EmployeeInvite extends Notification implements ShouldQueue
{
    use Queueable;

    /** A mail server that is briefly unreachable should not lose the email. */
    public $tries = 3;

    /** The QR's content-id inside the email. */
    public const QR_CID = 'kemp-sign-in.png';

    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        // Two views rather than one markdown template: the QR is embedded in
        // the HTML part only. A markdown mail renders its template twice, and
        // embedding from both would attach the picture twice.
        return (new MailMessage)
            ->subject(__('notifications.employee_invite.subject', ['app' => $app]))
            ->view(
                ['html' => 'emails.employee-invite', 'text' => 'emails.employee-invite-text'],
                [
                    'app'        => $app,
                    'name'       => $notifiable->name,
                    'email'      => $notifiable->email,
                    'company'    => $notifiable->company?->name,
                    'qrPng'      => QrCode::png(ActivationCode::PREFIX . $this->code),
                    'days'       => ActivationCode::VALID_DAYS,
                    'androidUrl' => config('mobile.store_url.android'),
                    'iosUrl'     => config('mobile.store_url.ios'),
                    'resetUrl'   => route('password.request'),
                ],
            );
    }
}
