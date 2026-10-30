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
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Odden\Core\Support\UserModel;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Services\MeetingInvite;

/**
 * Confirmation of a booked meeting, with an .ics invite. Sent to the invitee and to the host.
 */
class MeetingBookedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use ConfiguresSalesMail, Queueable, SerializesModels;

    /**
     * The invitee's signed cancel link (null in the host's copy). Built when the mail is created, during the booking, so it has the
     * host the booking page was opened on: the signature covers the host, and a queue worker would build it with the app URL instead.
     */
    public ?string $cancelUrl;

    /**
     * @param  'invitee'|'host'  $recipientRole
     */
    public function __construct(
        public SalesMeetingBooking $booking,
        public string $recipientRole = 'invitee',
    ) {
        $this->cancelUrl = $recipientRole === 'invitee'
            ? URL::signedRoute('odden.meetings.cancel.show', ['uid' => $booking->uid])
            : null;
        $this->configureSalesMail();
    }

    public function envelope(): Envelope
    {
        $booking = $this->booking;
        $link = $booking->meetingLink;
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
            subject: $this->recipientRole === 'host'
                ? "New booking: {$link->title} with {$booking->invitee_name} on {$when}"
                : "Confirmed: {$link->title} with {$hostName} on {$when}",
        );
    }

    public function content(): Content
    {
        $booking = $this->booking;

        return new Content(
            view: 'odden-sales::mail.meeting-booked',
            with: [
                'booking' => $booking,
                'link' => $booking->meetingLink,
                'hostName' => UserModel::displayName($booking->user, 'your host'),
                'recipientRole' => $this->recipientRole,
                'cancelUrl' => $this->cancelUrl,
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
            Attachment::fromData(fn (): string => $this->icsContent(), 'invite.ics')
                ->withMime('text/calendar; charset=UTF-8; method='.app(MeetingInvite::class)->method($this->booking)),
        ];
    }

    /**
     * The .ics calendar invite.
     */
    public function icsContent(): string
    {
        return app(MeetingInvite::class)->ics($this->booking);
    }
}
