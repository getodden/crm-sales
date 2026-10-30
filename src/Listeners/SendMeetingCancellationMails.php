<?php

declare(strict_types=1);

namespace Odden\Sales\Listeners;

use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Mail\MeetingCancelledMail;
use Odden\Sales\Mail\SalesMail;

/**
 * Emails the invitee and the host when a booking is cancelled. MeetingBookingCancelled fires once per
 * booking, from whichever path cancelled it, so both paths send the same mail and a second cancel sends none.
 */
class SendMeetingCancellationMails
{
    public function handle(MeetingBookingCancelled $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['meetingLink', 'user']);

        SalesMail::to($booking->invitee_email, $booking->invitee_name)->queue(
            new MeetingCancelledMail($booking, 'invitee', $event->cancelledBy, $event->actorName, $event->reason)
        );

        $hostEmail = $booking->user->getAttribute('email');

        if (is_string($hostEmail) && $hostEmail !== '') {
            SalesMail::to($hostEmail)->queue(
                new MeetingCancelledMail($booking, 'host', $event->cancelledBy, $event->actorName, $event->reason)
            );
        }
    }
}
