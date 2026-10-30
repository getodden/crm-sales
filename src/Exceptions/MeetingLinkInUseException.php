<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

/**
 * Thrown when something tries to delete a meeting link that still has upcoming active bookings: deleting it would
 * delete those bookings without cancelling them, so invitees are never told and a mirrored calendar keeps the event.
 */
class MeetingLinkInUseException extends LogicException
{
    public function __construct(public readonly int $upcomingBookings)
    {
        parent::__construct(sprintf(
            'This link has %d upcoming %s. Turn the link off instead, or wait until they have passed.',
            $upcomingBookings,
            $upcomingBookings === 1 ? 'booking' : 'bookings',
        ));
    }
}
