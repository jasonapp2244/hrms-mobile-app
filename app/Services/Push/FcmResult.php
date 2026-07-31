<?php

namespace App\Services\Push;

/**
 * What happened to one push.
 *
 * Three outcomes, not two. "Failed" and "this handset no longer exists" call
 * for opposite responses — one should be retried, the other should delete the
 * row so it is never tried again.
 */
class FcmResult
{
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $tokenIsDead = false,
        public readonly ?string $message = null,
    ) {}

    public static function delivered(): self
    {
        return new self(delivered: true);
    }

    public static function failed(string $message): self
    {
        return new self(delivered: false, message: $message);
    }

    /** Push is switched off or unconfigured. Not an error; nothing to report. */
    public static function skipped(string $message): self
    {
        return new self(delivered: false, message: $message);
    }
}
