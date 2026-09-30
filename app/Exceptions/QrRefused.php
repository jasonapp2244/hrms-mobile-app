<?php

namespace App\Exceptions;

/**
 * An office code that will not record a punch (A4.21), with the reason as a
 * wire code the app can branch on.
 *
 * Not a `RuntimeException`: the punch controllers already read that as "outside
 * the geofence", and a code that expired between the scan and the tap is a
 * different thing to tell somebody.
 */
class QrRefused extends \DomainException
{
    public function __construct(public readonly string $error)
    {
        parent::__construct(__('attendance.' . $error));
    }

    /** Unreadable, unknown, another company's, or a screen that was revoked. */
    public static function invalid(): self
    {
        return new self('qr_invalid');
    }

    public static function expired(): self
    {
        return new self('qr_expired');
    }

    public static function alreadyUsed(): self
    {
        return new self('qr_already_used');
    }
}
