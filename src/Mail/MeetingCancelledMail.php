<?php

declare(strict_types=1);

namespace Odden\Sales\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Arr;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Services\MeetingInvite;

/**
 * Tells the invitee or the host that a booked meeting was cancelled, with an .ics (METHOD:CANCEL) that removes it from their calendar.
 *
 * Unlike the other mails it carries a copy of the booking, its link and its host, not model identifiers: a cancelled booking no longer
 * blocks deleting its link, and deleting the link deletes its bookings, often before the queue sends this.
 */
class MeetingCancelledMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use ConfiguresSalesMail, Queueable;

    /**
     * @param  'invitee'|'host'  $recipientRole
     */
    public function __construct(
        public SalesMeetingBooking $booking,
        public string $recipientRole = 'invitee',
        public MeetingCancelledBy $cancelledBy = MeetingCancelledBy::Staff,
        public ?string $actorName = null,
        public ?string $reason = null,
    ) {
        $this->booking = self::snapshot($booking);
        $this->configureSalesMail();
    }

    /**
     * The booking with only the columns the mail and the .ics read from its link and host, so no other host attribute rides in the job.
     * The raw (still encrypted) values are copied, so nothing is decrypted here.
     */
    private static function snapshot(SalesMeetingBooking $booking): SalesMeetingBooking
    {
        $link = $booking->meetingLink;
        $host = $booking->user;

        return $booking->withoutRelations()->setRelations([
            'meetingLink' => $link->newInstance([], true)->setRawAttributes(Arr::only($link->getAttributes(), [$link->getKeyName(), 'title', 'description', 'slug'])),
            'user' => $host->newInstance([], true)->setRawAttributes(Arr::only($host->getAttributes(), [$host->getKeyName(), 'name', 'email'])),
        ]);
    }

    public function envelope(): Envelope
    {
        $booking = $this->booking;
        $hostName = UserModel::displayName($booking->user, 'your host');
        $when = $booking->starts_at->copy()->setTimezone($booking->timezone)->format('D, M j \a\t g:i A');

        $from = self::configuredFrom();
        $hostEmail = $booking->user->getAttribute('email');

        $replyTo = $this->recipientRole === 'host'
            ? [new Address($booking->invitee_email, $booking->invitee_name)]
            : (is_string($hostEmail) && $hostEmail !== '' ? [new Address($hostEmail, $hostName)] : []);

        return new Envelope(
            from: $from !== null ? new Address($from[0], $from[1]) : null,
            replyTo: $replyTo,
            subject: "Cancelled: {$booking->meetingLink->title} on {$when}",
        );
    }

    public function content(): Content
    {
        $booking = $this->booking;
        $hostName = UserModel::displayName($booking->user, 'your host');

        return new Content(
            view: 'odden-sales::mail.meeting-cancelled',
            with: [
                'booking' => $booking,
                'link' => $booking->meetingLink,
                'hostName' => $hostName,
                'recipientRole' => $this->recipientRole,
                'cancelledByName' => $this->cancelledByName($hostName),
                'reason' => $this->reason !== null && trim($this->reason) !== '' ? trim($this->reason) : null,
                'startsAt' => $booking->starts_at->copy()->setTimezone($booking->timezone),
                'endsAt' => $booking->ends_at->copy()->setTimezone($booking->timezone),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => app(MeetingInvite::class)->cancelIcs($this->booking), 'cancelled.ics')
                ->withMime('text/calendar; charset=UTF-8; method='.app(MeetingInvite::class)->cancelMethod($this->booking)),
        ];
    }

    private function cancelledByName(string $hostName): string
    {
        if ($this->cancelledBy === MeetingCancelledBy::Invitee) {
            return $this->recipientRole === 'host' ? $this->booking->invitee_name : 'you';
        }

        $actor = $this->actorName !== null && trim($this->actorName) !== '' ? trim($this->actorName) : $hostName;

        return $actor;
    }
}
