<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ContactLookup;
use Odden\Sales\Exceptions\MeetingSlotUnavailableException;
use Odden\Sales\Mail\MeetingBookedMail;
use Odden\Sales\Mail\SalesMail;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesSequenceEnrollment;
use Odden\Sales\Services\MeetingAvailability;

class BookMeetingAction
{
    public function __construct(
        protected MeetingAvailability $availability,
    ) {}

    /**
     * Book a meeting on a rep's calendar link: re-check the slot, record the booking, create or match
     * the Contact, log the meeting activity, and queue confirmations (with an .ics invite) to both sides.
     *
     * @return array{contact: Contact, activity: Activity, booking: SalesMeetingBooking}
     *
     * @throws MeetingSlotUnavailableException When the time is not an open slot on the link.
     */
    public function execute(
        SalesMeetingLink $link,
        string $fullName,
        string $email,
        CarbonInterface $scheduledAt,
        ?string $phone = null,
        ?string $notes = null
    ): array {
        $email = ContactLookup::normalizeEmail($email);

        return DB::transaction(
            fn (): array => $this->bookUnderLock($link, $fullName, $email, $scheduledAt, $phone, $notes)
        );
    }

    /**
     * Runs inside the booking transaction: serialize bookings per host by locking the host's links,
     * then re-check the slot under the lock before booking.
     *
     * @return array{contact: Contact, activity: Activity, booking: SalesMeetingBooking}
     *
     * @throws MeetingSlotUnavailableException When the time is not an open slot on the link.
     */
    protected function bookUnderLock(
        SalesMeetingLink $link,
        string $fullName,
        string $email,
        CarbonInterface $scheduledAt,
        ?string $phone,
        ?string $notes
    ): array {
        SalesMeetingLink::query()->where('user_id', $link->user_id)->lockForUpdate()->get(['id']);

        if (! $this->availability->isAvailable($link, $scheduledAt)) {
            throw MeetingSlotUnavailableException::at($scheduledAt);
        }

        return $this->book($link, $fullName, $email, $scheduledAt, $phone, $notes);
    }

    /**
     * @return array{contact: Contact, activity: Activity, booking: SalesMeetingBooking}
     */
    protected function book(
        SalesMeetingLink $link,
        string $fullName,
        string $email,
        CarbonInterface $scheduledAt,
        ?string $phone,
        ?string $notes
    ): array {
        // Datetime columns are written as-is, so store times in the app's timezone.
        $startsAt = CarbonImmutable::instance($scheduledAt)->setTimezone((string) config('app.timezone', 'UTC'));

        // Same lookup as the Service channels: case and surrounding whitespace are ignored, so a
        // contact saved as "Dana@Example.com" is reused rather than duplicated.
        $contact = ContactLookup::findByEmail($email);
        $matchedExisting = false;

        $parts = preg_split('/\s+/', trim($fullName));
        $partsArray = is_array($parts) ? $parts : [$fullName];
        $firstName = $partsArray[0] ?? $fullName;
        $lastName = count($partsArray) > 1 ? implode(' ', array_slice($partsArray, 1)) : '';

        if ($contact === null) {
            /** @var Contact $contact */
            $contact = Contact::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => ContactLookup::normalizeEmail($email),
                'phone' => $phone,
                'lead_status' => LeadStatus::Open,
                'owner_id' => $link->user_id,
            ]);
            $matchedExisting = false;
        } else {
            // The email address is typed by an anonymous visitor and not verified, so nothing the
            // visitor supplies is written onto an existing contact's record (no phone, no name).
            $matchedExisting = true;
        }

        $visitorNotes = $notes !== null && $notes !== '' ? $notes : null;
        $body = match (true) {
            $visitorNotes === null => "Booked online via /meet/{$link->slug}",
            $matchedExisting => "Booked online via /meet/{$link->slug} by an unverified visitor ({$fullName}, {$email}). Notes from the visitor: {$visitorNotes}",
            default => $visitorNotes,
        };

        /** @var Activity $activity */
        $activity = $contact->logActivity(
            type: ActivityType::Meeting,
            title: "{$link->title} with {$contact->full_name}",
            body: $body,
            metadata: [
                'duration_minutes' => $link->duration_minutes,
                'booking_slug' => $link->slug,
            ],
            status: ActivityStatus::Pending,
            dueAt: $startsAt,
            creatorId: $link->user_id
        );

        // Auto-unenroll contact from active outbound sales cadences upon meeting booking
        SalesSequenceEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->update([
                'status' => 'unenrolled',
                'next_step_due_at' => null,
            ]);

        /** @var SalesMeetingBooking $booking */
        $booking = SalesMeetingBooking::query()->create([
            'uid' => (string) Str::uuid(),
            'meeting_link_id' => $link->id,
            'user_id' => $link->user_id,
            'contact_id' => $contact->id,
            'activity_id' => $activity->id,
            'invitee_name' => trim($fullName),
            'invitee_email' => $email,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($link->duration_minutes),
            'timezone' => $link->timezoneName(),
        ]);

        $booking->setRelation('meetingLink', $link);

        // Queued, and only dispatched once the booking transaction commits.
        SalesMail::to($email, $booking->invitee_name)->queue(new MeetingBookedMail($booking, 'invitee'));

        $hostEmail = $link->user->getAttribute('email');

        if (is_string($hostEmail) && $hostEmail !== '') {
            SalesMail::to($hostEmail)->queue(new MeetingBookedMail($booking, 'host'));
        }

        return [
            'contact' => $contact,
            'activity' => $activity,
            'booking' => $booking,
        ];
    }
}
