<?php

namespace App\Notifications\Channels;

use App\Models\PushDevice;
use App\Services\Push\FcmClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification to every handset a person has registered.
 *
 * One row per installation, so somebody with a phone and a tablet gets both.
 * Each is sent separately because FCM's v1 API has no batch endpoint, and each
 * result is judged separately — one dead handset must not stop the other
 * receiving.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        if (! $this->client->configured()) {
            // Not an error worth failing a job over: the database and mail
            // copies have already been delivered by their own channels.
            return;
        }

        $userId = $notifiable->getKey();
        $devices = PushDevice::where('user_id', $userId)->get();

        if ($devices->isEmpty()) {
            return;
        }

        $message = $notification->toPush($notifiable);
        $dead = [];

        foreach ($devices as $device) {
            $result = $this->client->send($device->token, $message, $device->platform);

            if ($result->tokenIsDead) {
                $dead[] = $device->id;
            }
        }

        // Deleted after the loop rather than inside it, so one delete cannot
        // disturb the collection being iterated.
        if ($dead !== []) {
            PushDevice::whereIn('id', $dead)->delete();

            Log::info('FCM: removed handsets that no longer have the app.', [
                'user_id' => $userId,
                'removed' => count($dead),
            ]);
        }
    }
}
