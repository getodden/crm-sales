<?php

declare(strict_types=1);

namespace Odden\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Models\SalesMeetingBooking;

/**
 * A meeting booking was cancelled (fires once per booking). Dispatched after the database transaction commits.
 * $uid is the booking's stable key (the same UUID as the .ics event UID). A queued listener gets the
 * booking re-read from the database, so it fails (or is dropped, with $deleteWhenMissingModels) if the row is gone.
 * $cancelledBy says who cancelled, $actorName names a team member (null for the invitee), and $reason is what
 * they typed, if anything. The reason and the actor are not stored: they exist only on this event.
 */
class MeetingBookingCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public SalesMeetingBooking $booking,
        public string $uid,
        public MeetingCancelledBy $cancelledBy = MeetingCancelledBy::Staff,
        public ?string $actorName = null,
        public ?string $reason = null,
    ) {}
}
