<?php

namespace App\Jobs;

use App\Models\PushDevice;
use App\Notifications\Messages\PushMessage;
use App\Services\Push\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Sends one push to one handset again, after Google was busy or unreachable.
 *
 * Its own job, one per handset, rather than letting the notification's channel
 * job fail and retry: that job sends to *every* handset the person has, so a
 * 503 on the tablet would buzz the phone a second time for each retry.
 *
 * Without this a push that met a bad minute at Google was simply lost — the
 * database copy survived, but nobody's lock screen lit up.
 */
class RetryPush implements ShouldQueue
{
    use Queueable;

    /** Waits between attempts, in seconds: 1 min, 5 min, 15 min. */
    public const BACKOFF = [60, 300, 900];

    /**
     * The first send already happened in the channel; this counts retries.
     * Overrides the worker's --tries, which is tuned for mail.
     */
    public $tries = 3;

    /** The handset was signed out or forgotten meanwhile: nothing to send to. */
    public $deleteWhenMissingModels = true;

    public function __construct(
        public PushDevice $device,
        public PushMessage $message,
    ) {}

    /** Seconds before attempt number $attempt (1-based), never sooner than Google asked. */
    public static function delayFor(int $attempt, ?int $retryAfter): int
    {
        $backoff = self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1];

        return max($backoff, $retryAfter ?? 0);
    }

    public function handle(FcmClient $client): void
    {
        $result = $client->send($this->device->token, $this->message, $this->device->platform);

        if ($result->tokenIsDead) {
            $this->device->delete();

            return;
        }

        if (! $result->retryable) {
            // Delivered, switched off, or refused for a reason waiting cannot
            // fix (already logged by the client).
            return;
        }

        if ($this->attempts() >= $this->tries) {
            // Lands in failed_jobs, so `queue:retry` can send it once Google is
            // back, instead of vanishing.
            $this->fail(new RuntimeException($result->message ?? 'Push could not be delivered.'));

            return;
        }

        $this->release(self::delayFor($this->attempts() + 1, $result->retryAfter));
    }
}
