<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use RuntimeException;

/**
 * Thrown when one email address has booked meetings too often in a day. The booking page is public and the confirmation goes to
 * the address typed in, so an address cannot be made to receive an unbounded number of them.
 */
class MeetingBookingLimitException extends RuntimeException
{
    public static function forAddress(): self
    {
        return new self('This email address has reached the number of meetings it can book in a day.');
    }
}
