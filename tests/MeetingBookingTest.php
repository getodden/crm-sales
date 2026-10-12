<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Exceptions\MeetingSlotUnavailableException;
use Odden\Sales\Mail\MeetingBookedMail;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;
use Odden\Sales\Services\MeetingInvite;
use Odden\Sales\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    // Sunday 6 January 2030, 08:00 UTC. Monday is 2030-01-07.
    Carbon::setTestNow(Carbon::parse('2030-01-06 08:00:00', 'UTC'));
    Mail::fake();

    $this->host = User::factory()->create(['name' => 'Beth Caldwell', 'email' => 'beth@odden.test']);

    $this->makeLink = fn (array $attributes = []): SalesMeetingLink => SalesMeetingLink::query()->create(array_merge([
        'user_id' => $this->host->id,
        'slug' => 'beth-'.uniqid(),
        'title' => 'Discovery call',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-10:30']],
        'is_active' => true,
    ], $attributes));

    $this->times = fn (SalesMeetingLink $link, string $date): array => array_map(
        fn ($slot): string => $slot->format('H:i'),
        app(MeetingAvailability::class)->slotsFor($link, $date)
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('computes slots from working hours, duration and buffer', function (): void {
    $link = ($this->makeLink)(['buffer_minutes' => 15]);

    expect(($this->times)($link, '2030-01-07'))->toBe(['09:00', '09:45'])
        // No hours on Tuesday
        ->and(($this->times)($link, '2030-01-08'))->toBe([]);

    $noBuffer = ($this->makeLink)(['working_hours' => ['monday' => ['09:00-10:00', '13:00-14:00']]]);
    expect(($this->times)($noBuffer, '2030-01-07'))->toBe(['09:00', '09:30', '13:00', '13:30']);
});

it('uses the default working hours when none are set', function (): void {
    $link = ($this->makeLink)(['working_hours' => null, 'duration_minutes' => 60]);

    expect(($this->times)($link, '2030-01-07'))->toBe(['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'])
        ->and(($this->times)($link, '2030-01-12'))->toBe([]); // Saturday
});

it('interprets working hours in the link timezone', function (): void {
    $link = ($this->makeLink)(['timezone' => 'America/New_York', 'working_hours' => ['monday' => ['09:00-10:00']]]);

    $slots = app(MeetingAvailability::class)->slotsFor($link, '2030-01-07');

    expect($slots)->toHaveCount(2)
        ->and($slots[0]->getTimezone()->getName())->toBe('America/New_York')
        ->and($slots[0]->copy()->utc()->format('Y-m-d H:i'))->toBe('2030-01-07 14:00');
});

it('excludes past slots and slots already booked for the host, including buffer', function (): void {
    $link = ($this->makeLink)(['working_hours' => ['sunday' => ['07:00-09:00'], 'monday' => ['09:00-11:00']], 'buffer_minutes' => 10]);

    // Now is Sunday 08:00, so 07:00 and 07:40 have passed.
    expect(($this->times)($link, '2030-01-06'))->toBe(['08:20']);

    // A booking on another of the host's links at 09:30-10:00 blocks overlapping slots plus the buffer.
    $other = ($this->makeLink)(['working_hours' => ['monday' => ['09:00-17:00']]]);
    app(BookMeetingAction::class)->execute($other, 'Fox Mulder', 'fox@example.com', Carbon::parse('2030-01-07 09:30'));

    expect(($this->times)($link, '2030-01-07'))->toBe(['10:20']);
});

it('rejects a booking outside the computed slots', function (): void {
    $link = ($this->makeLink)();

    expect(fn () => app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'dana@example.com', Carbon::parse('2030-01-07 14:00')))
        ->toThrow(MeetingSlotUnavailableException::class);

    expect(Activity::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses to double-book a slot', function (): void {
    $link = ($this->makeLink)();
    $at = Carbon::parse('2030-01-07 09:00');

    app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'dana@example.com', $at);

    expect(fn () => app(BookMeetingAction::class)->execute($link, 'Fox Mulder', 'fox@example.com', $at))
        ->toThrow(MeetingSlotUnavailableException::class);

    expect(SalesMeetingBooking::query()->count())->toBe(1);
});

it('records the booking and queues confirmations with an ics invite to host and invitee', function (): void {
    $link = ($this->makeLink)();

    $result = app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'Dana@Example.com', Carbon::parse('2030-01-07 09:30'));

    /** @var SalesMeetingBooking $booking */
    $booking = $result['booking'];
    expect($booking->starts_at->format('Y-m-d H:i'))->toBe('2030-01-07 09:30')
        ->and($booking->ends_at->format('Y-m-d H:i'))->toBe('2030-01-07 10:00')
        ->and($booking->invitee_email)->toBe('dana@example.com')
        ->and($booking->activity_id)->toBe($result['activity']->id);

    expect(is_subclass_of(MeetingBookedMail::class, ShouldQueue::class))->toBeTrue();
    Mail::assertQueuedCount(2);

    foreach (['dana@example.com', 'beth@odden.test'] as $recipient) {
        Mail::assertQueued(MeetingBookedMail::class, function (MeetingBookedMail $mail) use ($recipient): bool {
            if (! $mail->hasTo($recipient)) {
                return false;
            }

            $mail->render(); // hydrates attachments()
            $ics = $mail->icsContent();

            return str_contains($ics, 'BEGIN:VCALENDAR')
                && str_contains($ics, 'DTSTART:20300107T093000Z')
                && str_contains($ics, 'DTEND:20300107T100000Z')
                && str_contains($ics, 'mailto:beth@odden.test')
                && str_contains($ics, 'mailto:dana@example.com')
                && $mail->hasAttachedData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST']);
        });
    }
});

it('shows computed slots on the booking page instead of fixed ones', function (): void {
    $link = ($this->makeLink)(['working_hours' => ['monday' => ['13:00-14:00']]]);

    $this->get("/meet/{$link->slug}?date=2030-01-07")
        ->assertSuccessful()
        ->assertSee('1:00 PM')
        ->assertSee('1:30 PM')
        ->assertDontSee('9:00 AM')
        ->assertDontSee('4:00 PM');

    // With no date, the page picks the first date that has slots.
    $this->get("/meet/{$link->slug}")
        ->assertSuccessful()
        ->assertSee('value="2030-01-07"', false)
        ->assertSee('1:00 PM');

    $this->get("/meet/{$link->slug}?date=2030-01-08")
        ->assertSuccessful()
        ->assertSee('No times are available');
});

it('books through the form with an accurate confirmation message', function (): void {
    $link = ($this->makeLink)();

    $this->post("/meet/{$link->slug}/book", [
        'name' => 'Dana Scully',
        'email' => 'dana@example.com',
        'date' => '2030-01-07',
        'time' => '09:30',
    ])
        ->assertRedirect("/meet/{$link->slug}")
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Monday, January 7 at 9:30 AM')
            && str_contains($status, 'dana@example.com')
            && ! str_contains($status, 'invitation has been dispatched'));

    expect(Activity::query()->where('type', ActivityType::Meeting)->count())->toBe(1);
});

it('sends the visitor back with an error when the slot was taken', function (): void {
    $link = ($this->makeLink)();
    app(BookMeetingAction::class)->execute($link, 'Fox Mulder', 'fox@example.com', Carbon::parse('2030-01-07 09:30'));

    $this->from("/meet/{$link->slug}?date=2030-01-07")
        ->post("/meet/{$link->slug}/book", [
            'name' => 'Dana Scully',
            'email' => 'dana@example.com',
            'date' => '2030-01-07',
            'time' => '09:30',
        ])
        ->assertRedirect("/meet/{$link->slug}?date=2030-01-07")
        ->assertSessionHasErrors('time');

    expect(SalesMeetingBooking::query()->count())->toBe(1);
});

it('delivers both confirmations through the real mailer once the queue runs', function (): void {
    // Undo Mail::fake(): use the array transport and the sync queue from phpunit.xml.dist.
    Mail::swap(new MailManager(app()));
    $link = ($this->makeLink)();

    app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'dana@example.com', Carbon::parse('2030-01-07 09:00'));

    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(2);

    $raw = $messages[0]->toString();
    expect($raw)->toContain('Content-Type: text/calendar; charset=UTF-8; method=REQUEST')
        ->and($raw)->toContain('invite.ics')
        ->and($raw)->toContain('Confirmed: Discovery call with Beth Caldwell');
});

it('books against an existing contact whatever the case or whitespace of its email', function (): void {
    $link = ($this->makeLink)();
    $existing = Contact::query()->create(['first_name' => 'Dana', 'email' => ' Dana.Scully@Example.com']);

    $result = app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'dana.scully@EXAMPLE.com ', Carbon::parse('2030-01-07 09:30'));

    expect($result['contact']->id)->toBe($existing->id)
        ->and(Contact::query()->count())->toBe(1);
});

it('stores a new contact booked online with the email lowercased and trimmed', function (): void {
    $link = ($this->makeLink)();

    $result = app(BookMeetingAction::class)->execute($link, 'Fox Mulder', '  Fox.Mulder@Example.com ', Carbon::parse('2030-01-07 09:30'));

    expect($result['contact']->email)->toBe('fox.mulder@example.com');
});

it('folds every kind of line break in invite text so a name cannot inject ics lines', function (): void {
    $link = ($this->makeLink)(['description' => "Agenda\rX-INJECTED-DESC:1"]);

    $result = app(BookMeetingAction::class)->execute($link, "Dana\rATTENDEE:mailto:evil@example.com\r\nX-INJECTED:1\nX-ALSO:1", 'dana@example.com', Carbon::parse('2030-01-07 09:30'));

    $ics = app(MeetingInvite::class)->ics($result['booking']);
    $lines = explode("\r\n", $ics);

    foreach ($lines as $line) {
        expect($line)->not->toContain("\r")
            ->and($line)->not->toContain("\n")
            ->and($line)->not->toStartWith('X-INJECTED')
            ->and($line)->not->toStartWith('X-ALSO');
    }

    expect(collect($lines)->filter(fn (string $line): bool => str_starts_with($line, 'ATTENDEE'))->count())->toBe(1);
    expect($ics)->toContain('DESCRIPTION:Agenda\\nX-INJECTED-DESC:1');
});

it('does not let an unverified visitor change an existing contact', function (): void {
    $link = ($this->makeLink)();
    $contact = Contact::query()->create(['email' => 'dana@example.com', 'first_name' => 'Dana']);

    app(BookMeetingAction::class)->execute(
        $link,
        'Someone Else',
        'Dana@Example.com',
        Carbon::parse('2030-01-07 09:00:00', 'UTC'),
        '+1 555 0100',
        'Please call me about the invoice'
    );

    $contact->refresh();
    $activity = Activity::query()->where('type', ActivityType::Meeting)->latest('id')->first();

    expect($contact->phone)->toBeNull()
        ->and($contact->first_name)->toBe('Dana')
        ->and($activity->body)->toContain('unverified visitor')
        ->and($activity->body)->toContain('Please call me about the invoice');
});

it('still records the visitor details on a contact it creates', function (): void {
    $link = ($this->makeLink)();

    $result = app(BookMeetingAction::class)->execute(
        $link,
        'New Person',
        'new.person@example.com',
        Carbon::parse('2030-01-07 09:00:00', 'UTC'),
        '+1 555 0101',
        'Looking forward to it'
    );

    expect($result['contact']->phone)->toBe('+1 555 0101')
        ->and($result['activity']->body)->toBe('Looking forward to it');
});

it('creates a new contact through CreateContactAction so ContactCreated fires', function (): void {
    Event::fake([ContactCreated::class]);
    $link = ($this->makeLink)();

    $result = app(BookMeetingAction::class)->execute($link, 'New Person', ' New.Person@Example.com', Carbon::parse('2030-01-07 09:00'), '+1 555 0102');

    Event::assertDispatchedTimes(ContactCreated::class, 1);
    Event::assertDispatched(ContactCreated::class, fn (ContactCreated $event): bool => $event->contact->is($result['contact']));

    expect($result['contact']->email)->toBe('new.person@example.com')
        ->and($result['contact']->first_name)->toBe('New')
        ->and($result['contact']->last_name)->toBe('Person')
        ->and($result['contact']->phone)->toBe('+1 555 0102')
        ->and($result['contact']->owner_id)->toBe($this->host->id);
});

it('does not fire ContactCreated when the booking matches an existing contact', function (): void {
    $link = ($this->makeLink)();
    Contact::query()->create(['first_name' => 'Dana', 'email' => 'dana@example.com']);
    Event::fake([ContactCreated::class]);

    app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'DANA@example.com', Carbon::parse('2030-01-07 09:00'));

    Event::assertNotDispatched(ContactCreated::class);
});

it('creates one contact when the same new address books with two hosts', function (): void {
    Event::fake([ContactCreated::class]);
    $first = ($this->makeLink)();
    $otherHost = User::factory()->create(['email' => 'other@odden.test']);
    $second = ($this->makeLink)(['user_id' => $otherHost->id]);

    $a = app(BookMeetingAction::class)->execute($first, 'New Person', 'new.person@example.com', Carbon::parse('2030-01-07 09:00'), null, 'First booking');
    $b = app(BookMeetingAction::class)->execute($second, 'Someone Else', 'New.Person@example.com', Carbon::parse('2030-01-07 09:00'), '+1 555 0199', 'Second booking');

    Event::assertDispatchedTimes(ContactCreated::class, 1);

    expect($b['contact']->id)->toBe($a['contact']->id)
        ->and(Contact::query()->count())->toBe(1)
        // The second visitor is unverified: the contact the first booking created is not changed.
        ->and($a['contact']->refresh()->phone)->toBeNull()
        ->and($a['contact']->first_name)->toBe('New')
        ->and($b['activity']->body)->toContain('unverified visitor')
        ->and($b['activity']->body)->toContain('Second booking');
});

it('does not create a contact when the slot is not available', function (): void {
    Event::fake([ContactCreated::class]);
    $link = ($this->makeLink)();

    expect(fn () => app(BookMeetingAction::class)->execute($link, 'New Person', 'new.person@example.com', Carbon::parse('2030-01-07 14:00')))
        ->toThrow(MeetingSlotUnavailableException::class);

    Event::assertNotDispatched(ContactCreated::class);
    expect(Contact::query()->count())->toBe(0);
});
