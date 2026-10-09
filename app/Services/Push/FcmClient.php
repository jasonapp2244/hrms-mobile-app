<?php

namespace App\Services\Push;

use App\Notifications\Messages\PushMessage;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to Firebase Cloud Messaging.
 *
 * FCM's HTTP v1 API is one request per token — there is no batch endpoint any
 * more — and it authenticates with a short-lived OAuth2 token minted from a
 * service-account key rather than the old static server key.
 *
 * The important behaviour here is not sending. It is knowing which tokens are
 * dead: an app uninstalled six months ago still has a row in push_devices, and
 * every notification to that person would try it again forever. FCM says so
 * explicitly, and this reports it back so the caller can delete the row.
 */
class FcmClient
{
    /** Google's own cache lifetime is an hour; expire early to avoid racing it. */
    private const TOKEN_CACHE_KEY = 'fcm.access_token';
    private const TOKEN_CACHE_SECONDS = 3300;

    public function configured(): bool
    {
        return (bool) config('fcm.enabled')
            && filled(config('fcm.project_id'))
            && is_readable((string) config('fcm.credentials'));
    }

    /**
     * Sends one message to one handset.
     *
     * @return FcmResult Whether it landed, and whether the token should be dropped.
     */
    public function send(string $deviceToken, PushMessage $message, string $platform = 'android'): FcmResult
    {
        if (! $this->configured()) {
            return FcmResult::skipped('Push is not configured.');
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            // A broken key file is an operator problem, not this person's. Log
            // it once per send rather than failing the whole notification and
            // losing the database and mail copies with it.
            Log::error('FCM: could not obtain an access token.', ['error' => $e->getMessage()]);

            return FcmResult::failed('Authentication with Firebase failed.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('fcm.timeout'))
                ->post(
                    sprintf(
                        'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                        config('fcm.project_id'),
                    ),
                    ['message' => $this->payload($deviceToken, $message, $platform)],
                );
        } catch (ConnectionException $e) {
            // A timeout or a DNS hiccup says nothing about the handset. Thrown
            // out of here it would fail the whole channel and resend to every
            // handset that had already received it.
            Log::warning('FCM: could not reach Google.', ['error' => $e->getMessage()]);

            return FcmResult::transient('Could not reach Firebase.');
        }

        if ($response->successful()) {
            return FcmResult::delivered();
        }

        if ($response->status() === 401) {
            // The cached access token was revoked or expired early. Forget it so
            // the retry mints a fresh one instead of failing the same way.
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        return $this->interpretFailure(
            $response->status(),
            $response->json() ?? [],
            $this->retryAfter($response->header('Retry-After')),
        );
    }

    /**
     * The message body FCM expects.
     *
     * `notification` is what the OS displays; `data` is what the app reads when
     * somebody taps it. Both are sent — a data-only message would not appear on
     * the lock screen while the app is closed, which is precisely when a
     * clock-out reminder matters.
     */
    private function payload(string $token, PushMessage $message, string $platform): array
    {
        $payload = [
            'token'        => $token,
            'notification' => [
                'title' => $message->title,
                'body'  => $message->body,
            ],
            'data' => array_map(static fn ($value) => (string) $value, $message->data),
        ];

        if ($platform === 'ios') {
            $payload['apns'] = [
                'payload' => [
                    'aps' => [
                        // Without this iOS shows nothing while the app is in the
                        // foreground, and no badge at all.
                        'sound' => 'default',
                        'badge' => 1,
                    ],
                ],
            ];
        } else {
            $payload['android'] = [
                // A reminder to clock out is worth waking the device for; it is
                // useless an hour later.
                'priority'     => 'high',
                'notification' => [
                    // Android 8+ drops any notification whose channel does not
                    // exist on the handset, silently.
                    'channel_id' => (string) config('fcm.android_channel'),
                ],
            ];
        }

        return $payload;
    }

    /**
     * Turns FCM's error into "try again later", "this token is dead", or neither.
     *
     * The distinction is the whole point: a 503 is Google having a bad minute
     * and should be retried, while UNREGISTERED means the app is gone from that
     * handset and retrying forever would be the bug.
     *
     * INVALID_ARGUMENT is the trap. FCM answers it for a malformed token, but
     * also for a malformed *message* — a title over the size limit, a bad data
     * key. Reading it as "dead token" would let one bad notification delete
     * every handset it was addressed to, silently unsubscribing the whole
     * company. It only counts as dead when FCM names the token as the field at
     * fault.
     */
    private function interpretFailure(int $status, array $body, ?int $retryAfter = null): FcmResult
    {
        $details = $body['error']['details'] ?? [];
        $reason = collect($details)->pluck('errorCode')->filter()->first()
            ?? $body['error']['status']
            ?? 'UNKNOWN';

        $message = sprintf('FCM refused the message (%s).', $reason);

        $badTokenField = collect($details)
            ->flatMap(fn ($detail) => $detail['fieldViolations'] ?? [])
            ->contains(fn ($violation) => ($violation['field'] ?? null) === 'message.token');

        $deadToken = $reason === 'UNREGISTERED'
            || ($reason === 'INVALID_ARGUMENT' && $badTokenField)
            // 404 is FCM's answer for a token it has never heard of.
            || $status === 404;

        if ($deadToken) {
            return new FcmResult(delivered: false, tokenIsDead: true, message: $message);
        }

        // Overloaded, rate-limited, or our access token went stale: the same
        // request can succeed later.
        // THIRD_PARTY_AUTH_ERROR also arrives as a 401, but it is Apple refusing
        // our APNs key, which no amount of waiting fixes.
        $retryable = $reason !== 'THIRD_PARTY_AUTH_ERROR'
            && (in_array($status, [401, 429, 500, 502, 503, 504], true)
                || in_array($reason, ['UNAVAILABLE', 'INTERNAL', 'QUOTA_EXCEEDED'], true));

        if ($retryable) {
            Log::warning('FCM: delivery failed, will retry.', ['status' => $status, 'reason' => $reason]);

            return FcmResult::transient($message, $retryAfter);
        }

        // Anything else is ours to fix — a payload FCM will never accept, or a
        // missing APNs key. Neither retrying nor forgetting the handset helps.
        Log::error('FCM: message refused.', ['status' => $status, 'reason' => $reason, 'body' => $body]);

        return FcmResult::failed($message);
    }

    /** Retry-After in seconds; FCM sends a number, never an HTTP date. */
    private function retryAfter(?string $header): ?int
    {
        return is_numeric($header) ? max(0, (int) $header) : null;
    }

    /**
     * A short-lived OAuth2 token, cached so every notification in a batch does
     * not mint its own.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_SECONDS, function (): string {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                (string) config('fcm.credentials'),
            );

            $token = $credentials->fetchAuthToken()['access_token'] ?? null;

            if (! $token) {
                throw new RuntimeException('Firebase returned no access token.');
            }

            return $token;
        });
    }
}
