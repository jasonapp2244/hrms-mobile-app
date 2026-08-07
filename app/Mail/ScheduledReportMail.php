<?php

namespace App\Mail;

use App\Models\ReportSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled report, delivered (A7.12).
 *
 * The file is built before the mail is constructed and passed in as raw bytes
 * rather than a path. A queued mailable is serialised and may be sent minutes
 * later on another process; a temporary file written by the command would not
 * reliably still be there, and writing the report into storage would leave
 * everybody's hours lying around on disk after the send.
 *
 * The body repeats the headline figures the attachment opens with. Plenty of
 * recipients read the mail on a phone and only open the attachment if a number
 * looks wrong, and for them the tiles are the report.
 */
class ScheduledReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The period is `periodFrom`/`periodTo` rather than the obvious `from`/`to`:
     * Mailable already owns both of those names for the sender and recipient
     * lists, and redeclaring either is a fatal error at class load.
     *
     * @param  array<int, array{label: string, value: mixed}>  $tiles
     */
    public function __construct(
        public ReportSubscription $subscription,
        public string $reportTitle,
        public string $periodFrom,
        public string $periodTo,
        public array $tiles,
        public string $filename,
        protected string $fileContents,
        protected string $mimeType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s — %s to %s', $this->reportTitle, $this->periodFrom, $this->periodTo),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.scheduled-report');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->fileContents, $this->filename)
                ->withMime($this->mimeType),
        ];
    }

    /**
     * What is actually attached.
     *
     * Attachment hides its bytes behind a resolver, which leaves no way to
     * check that a report rendered to something rather than to an empty file —
     * a distinction the recipient notices and the send does not.
     */
    public function attachmentBytes(): string
    {
        return $this->fileContents;
    }
}
