<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Messages\PushMessage;
use App\Support\AppRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An announcement from HR, on everybody's phone (B5.5).
 *
 * The odd one out here in one respect: **the words are not the system's.** Every
 * other notification is a sentence this codebase wrote, translated into English
 * and Spanish and rendered in the recipient's language. This one carries what a
 * person typed, and it goes out exactly as typed — the same reason leave type
 * names and office names are not translated. A machine translation of "the
 * Croydon depot closes at 2pm on Friday" is a liability, not a courtesy, and HR
 * writing the announcement is the one who knows who reads what.
 *
 * The title and body are therefore taken from the model rather than from
 * `lang/`, and there is no `__()` call anywhere below.
 */
class CompanyAnnouncement extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Kept even if the announcement row is deleted.
     *
     * The default would drop the job, which is exactly wrong here: the register
     * refuses to delete a published announcement, so the only way this fires is
     * a draft being deleted mid-publish — and a person who has already been
     * pushed the message should still find it in their history. The title and
     * body are copied into the constructor rather than read back off the model
     * for the same reason.
     */
    public $deleteWhenMissingModels = false;

    public function __construct(
        public int $announcementId,
        public string $title,
        public string $body,
    ) {}

    public static function for(Announcement $announcement): self
    {
        return new self(
            announcementId: $announcement->id,
            title: $announcement->title,
            body: $announcement->body,
        );
    }

    /**
     * The bell and the handset.
     *
     * No mail leg. An announcement is the one broadcast where an emailed copy
     * would be genuinely welcome, and it is still not here: `MAIL_MAILER` is
     * `log` (A9.2), and the first thing a newly deployed server would do is send
     * two hundred identical messages from an IP with no sending reputation.
     * Adding `'mail'` to this array is the whole change, once mail is live.
     */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            config('fcm.enabled') ? 'fcm' : null,
        ]));
    }

    /** Written as the publish request finishes; there is no mail leg to queue. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->title,
            // An opening, not the message. A long announcement in a data
            // payload is a failed send for every recipient rather than a long
            // notification; the full text is already in the row behind it.
            body: Str::limit($this->body, Announcement::PUSH_PREVIEW_CHARS),
            data: [
                'type'  => 'announcement',
                'id'    => $this->announcementId,
                'route' => AppRoute::forType('announcement'),
            ],
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'  => 'announcement',
            'title' => $this->title,
            // In full. The notification centre renders the body without
            // truncating it, and for this type the body *is* the message —
            // there is no screen to send anybody to for the rest of it.
            'body'            => $this->body,
            'announcement_id' => $this->announcementId,
            'url'             => route('announcements.index'),
        ];
    }
}
