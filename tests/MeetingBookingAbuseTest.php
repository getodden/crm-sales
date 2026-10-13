<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Exceptions\MeetingBookingLimitException;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC', 'odden-sales.meetings.max_bookings_per_email_per_day' => 3]);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2030-01-06 08:00:00', 'UTC'));
    Mail::fake();

    $host = User::factory()->create(['name' => 'Beth Caldwell', 'email' => 'beth@odden.test']);
    $this->link = SalesMeetingLink::query()->create([
        'user_id' => $host->id, 'slug' => 'beth', 'title' => 'Discovery call', 'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-17:00']], 'is_active' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('turns an address away after it has booked its daily number of meetings', function (): void {
    foreach (['09:00', '10:00', '11:00'] as $time) {
        app(BookMeetingAction::class)->execute($this->link, 'Pat Smith', 'pat@example.com', Carbon::parse("2030-01-07 {$time}"));
    }

    expect(fn () => app(BookMeetingAction::class)->execute($this->link, 'Pat Smith', 'PAT@example.com', Carbon::parse('2030-01-07 12:00')))
        ->toThrow(MeetingBookingLimitException::class)
        ->and(SalesMeetingBooking::query()->count())->toBe(3);

    // Another address is unaffected, and the limit is a day.
    app(BookMeetingAction::class)->execute($this->link, 'Lee Park', 'lee@example.com', Carbon::parse('2030-01-07 12:00'));
    expect(SalesMeetingBooking::query()->count())->toBe(4);
});

it('can have the limit turned off', function (): void {
    config(['odden-sales.meetings.max_bookings_per_email_per_day' => 0]);

    foreach (['09:00', '10:00', '11:00', '12:00'] as $time) {
        app(BookMeetingAction::class)->execute($this->link, 'Pat Smith', 'pat@example.com', Carbon::parse("2030-01-07 {$time}"));
    }

    expect(SalesMeetingBooking::query()->count())->toBe(4);
});

it('prints a booker\'s name as plain text without links or markup', function (): void {
    $result = app(BookMeetingAction::class)->execute(
        $this->link,
        "Eve <b>Pay now</b> https://evil.example/pay\r\nBcc: x@example.com",
        'eve@example.com',
        Carbon::parse('2030-01-07 09:00'),
    );

    $name = $result['booking']->invitee_name;

    expect($name)->not->toContain('<')->not->toContain('http')->not->toContain("\n")
        ->and($name)->toStartWith('Eve');
});

it('falls back to the address when the name is nothing but a link', function (): void {
    $result = app(BookMeetingAction::class)->execute($this->link, 'https://evil.example/pay-now', 'eve@example.com', Carbon::parse('2030-01-07 09:00'));

    expect($result['booking']->invitee_name)->toBe('eve');
});

it('does not count a booking that was refused for its slot against the address', function (): void {
    RateLimiter::clear('odden-sales:meeting-booking:'.sha1('pat@example.com'));

    foreach (range(1, 5) as $i) {
        try {
            app(BookMeetingAction::class)->execute($this->link, 'Pat Smith', 'pat@example.com', Carbon::parse('2030-01-07 03:00'));
        } catch (Throwable) {
        }
    }

    app(BookMeetingAction::class)->execute($this->link, 'Pat Smith', 'pat@example.com', Carbon::parse('2030-01-07 09:00'));

    expect(SalesMeetingBooking::query()->count())->toBe(1);
});
