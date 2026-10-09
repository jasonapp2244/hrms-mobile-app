<?php

namespace App\Services\Push;

/**
 * What happened to one push.
 *
 * Four outcomes, not two. "Try again later" and "this handset no longer exists"
 * call for opposite responses — one should be retried, the other should delete
 * the row so it is never tried again — and a refusal that is neither (a bad
 * payload, a broken APNs key) must do neither: retrying cannot fix it, and
 * deleting the handset would punish the person for our mistake.
 */
class FcmResult
{
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $tokenIsDead = false,
        public readonly ?string $message = null,
        /** Google was busy or unreachable; the same request may succeed later. */
        public readonly bool $retryable = false,
        /** Seconds Google asked us to wait (Retry-After), when it said. */
        public readonly ?int $retryAfter = null,
    ) {}

    public static function delivered(): self
    {
        return new self(delivered: true);
    }

    public static function failed(string $message): self
    {
        return new self(delivered: false, message: $message);
    }

    public static function transient(string $message, ?int $retryAfter = null): self
    {
        return new self(delivered: false, message: $message, retryable: true, retryAfter: $retryAfter);
    }

    /** Push is switched off or unconfigured. Not an error; nothing to report. */
    public static function skipped(string $message): self
    {
        return new self(delivered: false, message: $message);
    }
}
