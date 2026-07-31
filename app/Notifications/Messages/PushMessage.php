<?php

namespace App\Notifications\Messages;

/**
 * What arrives on a handset's lock screen.
 *
 * Deliberately small. A push is a tap on the shoulder, not the message itself —
 * the detail lives in the app, and the same notification has already been
 * written to the database for the in-app centre.
 */
class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        /**
         * Delivered alongside the notification so tapping it can open the right
         * screen. Values must be strings — FCM rejects a data payload with any
         * other type, and an int slipping in here would fail every send for
         * that notification rather than one.
         */
        public array $data = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body'  => $this->body,
            'data'  => array_map(static fn ($value) => (string) $value, $this->data),
        ];
    }
}
