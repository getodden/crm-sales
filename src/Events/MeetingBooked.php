<?php

declare(strict_types=1);

namespace Odden\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Odden\Sales\Models\SalesMeetingBooking;

/**
 * A new meeting booking was committed. Dispatched after the database transaction commits.
 * $uid is the booking's stable key (the same UUID as the .ics event UID). A queued listener gets the
 * booking re-read from the database, so it fails (or is dropped, with $deleteWhenMissingModels) if the row is gone.
 */
class MeetingBooked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public SalesMeetingBooking $booking,
        public string $uid
    ) {}
}
