<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ContactLookup;
use Odden\Sales\Exceptions\MeetingBookingLimitException;
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
     * A new contact is created through Core's ContactLookup::findOrCreate (CreateContactAction), so
     * ContactCreated fires, the email is normalized and checked, and two bookings of the same new
     * address at once create one contact. That happens before the booking transaction, because the
     * lookup's lock is released when the contact is created: inside an open transaction a second
     * booking could not see the uncommitted contact yet and would create another. A slot taken in the
     * moment between the two steps therefore leaves the new contact without a booking.
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
        $fullName = $this->plainName($fullName, $email);

        // The only check against the host's external busy time. Bookings are checked again under the
        // lock below; this also avoids creating a contact for a slot that is already gone.
        if (! $this->availability->isAvailable($link, $scheduledAt)) {
            throw MeetingSlotUnavailableException::at($scheduledAt);
        }

        // The confirmation goes to the address typed in, with the name typed in: one address can only be sent so many.
        $this->limitPerAddress($email);

        [$contact, $matchedExisting] = $this->resolveContact($link, $fullName, $email, $phone);

        return DB::transaction(
            fn (): array => $this->bookUnderLock($link, $contact, $matchedExisting, $fullName, $email, $scheduledAt, $notes)
        );
    }

    /**
     * The booker's name as it is printed in mail and on the rep's calendar: plain one-line text, with no links or markup (it is
     * typed by an anonymous visitor into a confirmation that goes out under the host's name). A name that is nothing else falls
     * back to the address's local part.
     */
    protected function plainName(string $name, string $email): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $name = preg_replace('~(?:https?://|www\.)\S+~i', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', str_replace(['<', '>'], '', $name)) ?? '');
        $name = mb_substr($name, 0, 120);

        return mb_strlen($name) >= 2 ? $name : Str::before($email, '@');
    }

    /**
     * @throws MeetingBookingLimitException
     */
    protected function limitPerAddress(string $email): void
    {
        $max = (int) config('odden-sales.meetings.max_bookings_per_email_per_day', 3);

        if ($max <= 0) {
            return;
        }

        $key = 'odden-sales:meeting-booking:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw MeetingBookingLimitException::forAddress();
        }

        RateLimiter::hit($key, 86400);
    }

    /**
     * The contact with this email (case and surrounding whitespace ignored, as in the Service
     * channels), or a new one with the visitor's details, and whether an existing contact matched.
     *
     * The email address is typed by an anonymous visitor and not verified, so nothing the visitor
     * supplies is written onto an existing contact's record (no phone, no name): the attributes are
     * only used when the contact is created.
     *
     * @return array{0: Contact, 1: bool}
     */
    protected function resolveContact(SalesMeetingLink $link, string $fullName, string $email, ?string $phone): array
    {
        $parts = preg_split('/\s+/', trim($fullName));
        $partsArray = is_array($parts) ? $parts : [$fullName];
        $firstName = $partsArray[0] ?? $fullName;
        $lastName = count($partsArray) > 1 ? implode(' ', array_slice($partsArray, 1)) : '';

        $contact = ContactLookup::findOrCreate($email, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'lead_status' => LeadStatus::Open,
            'owner_id' => $link->user_id,
        ]);

        // Created by this call only; a contact found, or created by a concurrent booking, is existing.
        return [$contact, ! $contact->wasRecentlyCreated];
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
        Contact $contact,
        bool $matchedExisting,
        string $fullName,
        string $email,
        CarbonInterface $scheduledAt,
        ?string $notes
    ): array {
        SalesMeetingLink::query()->where('user_id', $link->user_id)->lockForUpdate()->get(['id']);

        if (! $this->availability->isAvailable($link, $scheduledAt, withExternal: false)) {
            throw MeetingSlotUnavailableException::at($scheduledAt);
        }

        return $this->book($link, $contact, $matchedExisting, $fullName, $email, $scheduledAt, $notes);
    }

    /**
     * @return array{contact: Contact, activity: Activity, booking: SalesMeetingBooking}
     */
    protected function book(
        SalesMeetingLink $link,
        Contact $contact,
        bool $matchedExisting,
        string $fullName,
        string $email,
        CarbonInterface $scheduledAt,
        ?string $notes
    ): array {
        // Datetime columns are written as-is, so store times in the app's timezone.
        $startsAt = CarbonImmutable::instance($scheduledAt)->setTimezone((string) config('app.timezone', 'UTC'));

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

        // Auto-unenroll contact from active outbound sales cadences upon meeting booking. That includes a booking under an
        // existing contact's address that nobody verified: a meeting is now on the rep's calendar for this person, and the
        // activity above says so on their timeline. (The per-address limit bounds how often one address can be used.)
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
