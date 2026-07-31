<?php

namespace App\Services\Push;

use App\Notifications\Messages\PushMessage;
use Google\Auth\Credentials\ServiceAccountCredentials;
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

        $response = Http::withToken($accessToken)
            ->timeout((int) config('fcm.timeout'))
            ->post(
                sprintf(
                    'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                    config('fcm.project_id'),
                ),
                ['message' => $this->payload($deviceToken, $message, $platform)],
            );

        if ($response->successful()) {
            return FcmResult::delivered();
        }

        return $this->interpretFailure($response->status(), $response->json() ?? []);
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
     * Turns FCM's error into either "try again later" or "this token is dead".
     *
     * The distinction is the whole point: a 503 is Google having a bad minute
     * and the job should retry, while UNREGISTERED means the app is gone from
     * that handset and retrying forever would be the bug.
     */
    private function interpretFailure(int $status, array $body): FcmResult
    {
        $reason = $body['error']['details'][0]['errorCode']
            ?? $body['error']['status']
            ?? 'UNKNOWN';

        $deadToken = in_array($reason, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)
            // 404 is FCM's answer for a token it has never heard of.
            || $status === 404;

        if (! $deadToken) {
            Log::warning('FCM: delivery failed.', ['status' => $status, 'reason' => $reason]);
        }

        return new FcmResult(
            delivered: false,
            tokenIsDead: $deadToken,
            message: sprintf('FCM refused the message (%s).', $reason),
        );
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
