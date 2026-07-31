<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to edit or remove an attendance record.
 *
 * Its own class rather than a bare RuntimeException so a future correction
 * feature can catch precisely this, and so the reason shows plainly in a stack
 * trace instead of reading like a generic failure.
 */
class AttendanceIsImmutable extends RuntimeException
{
}
