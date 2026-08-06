<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "set a new password" link.
 *
 * Replaces Laravel's built-in ResetPassword notification so the wording matches
 * the rest of the product and so it queues like every other mail here.
 *
 * Mail only — deliberately. This is the one message that must not go to the
 * in-app bell or a push: somebody asking for a password reset cannot sign in to
 * read a notification, and a reset link on a lock screen is a reset link for
 * whoever is holding the phone.
 */
class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    /** A mail server that is briefly unreachable should not lose the link. */
    public $tries = 3;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your ' . config('app.name') . ' password')
            ->greeting('Hello ' . ($notifiable->name ?: 'there') . ',')
            ->line('Someone asked to reset the password for this account.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link stops working in {$minutes} minutes, and can only be used once.")
            // No "contact support" instruction: an unsolicited reset request is
            // usually a mistyped address, not an attack, and telling people to
            // report every one of them trains them to ignore the real thing.
            ->line('If this was not you, nothing has changed — you can ignore this email and your password stays as it is.');
    }

    /**
     * The signed link.
     *
     * The address travels with the token because the reset form has to know
     * which account it is setting a password for, and the token alone does not
     * say — the broker hashes it against the email.
     */
    protected function resetUrl(object $notifiable): string
    {
        return route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
