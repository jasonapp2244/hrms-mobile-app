<?php

namespace App\Notifications;

use App\Notifications\Messages\PushMessage;
use App\Support\AppRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A policy rule matched (A2.9).
 *
 * Like `CompanyAnnouncement`, **the words are not the system's**: the title is
 * the rule's own name, typed by an administrator, and it goes out as typed. A
 * translated "Late arrivals — Croydon" would be a guess at what somebody meant
 * by their own label. The sentence around it is the system's and is short
 * enough to need no template.
 *
 * Everything below is copied into the constructor rather than read back off the
 * rule, for the reason `$deleteWhenMissingModels = false` exists: the rule can
 * be edited or switched off between the punch and the job running, and a person
 * told "a rule fired" should be told *which*, as it read when it fired.
 *
 * **It carries no link**, and that is a decision rather than an omission. One
 * rule can address HR, an administrator, the line manager and the employee at
 * once; a notification carries one destination for all of them, and the screens
 * worth pointing at are permission-gated, so most of that audience would tap
 * through to a refusal. Without a url the notification opens the notification
 * list — see `NotificationController::open` — and the sentence is the whole
 * message anyway.
 *
 * **No mail leg**, matching every other broadcast here while `MAIL_MAILER` is
 * `log` (A9.2). Adding `'mail'` to `via()` is the whole change once mail is
 * live — and it is the notification most likely to want one, because the people
 * a rule addresses are often not the ones holding the app.
 */
class PolicyRuleFired extends Notification implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = false;

    public function __construct(
        public int $ruleId,
        public string $ruleName,
        /** What it matched, in a sentence: "Ann Lee clocked in late". */
        public string $subject,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    /**
     * Written as the punch finishes.
     *
     * The database leg is synchronous deliberately: the row it writes is the
     * only durable record the recipient has, and a queue that is not running —
     * which on this host is a cron, not a daemon — would lose it silently.
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->ruleName,
            body: Str::limit($this->subject, 160),
            data: array_filter([
                'type'  => 'policy_rule',
                'id'    => (string) $this->ruleId,
                // Null today — the app has no rules screen, and inventing one
                // to point at would be worse than opening where the person
                // already was. Read from AppRoute rather than hard-coded so it
                // follows the app if that ever changes.
                'route' => AppRoute::forType('policy_rule'),
            ]),
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'    => 'policy_rule',
            'title'   => $this->ruleName,
            'body'    => $this->subject,
            'rule_id' => $this->ruleId,
        ];
    }
}
