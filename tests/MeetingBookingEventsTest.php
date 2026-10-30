<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Events\MeetingBooked;
use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Exceptions\MeetingLinkInUseException;
use Odden\Sales\Exceptions\MeetingSlotUnavailableException;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2030-01-06 08:00:00', 'UTC'));
    Mail::fake();

    $host = User::factory()->create(['email' => 'beth@odden.test']);

    $this->link = SalesMeetingLink::query()->create([
        'user_id' => $host->id,
        'slug' => 'beth-events',
        'title' => 'Discovery call',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-10:30']],
        'is_active' => true,
    ]);

    $this->book = fn (string $time = '09:30'): array => app(BookMeetingAction::class)
        ->execute($this->link, 'Dana Scully', 'dana@example.com', Carbon::parse("2030-01-07 {$time}"));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('fires MeetingBooked once with the booking and its uid', function (): void {
    Event::fake([MeetingBooked::class]);

    $booking = ($this->book)()['booking'];

    Event::assertDispatchedTimes(MeetingBooked::class, 1);
    Event::assertDispatched(MeetingBooked::class, fn (MeetingBooked $event): bool => $event->booking->is($booking) && $event->uid === $booking->uid);
});

it('fires nothing when the slot is unavailable', function (): void {
    Event::fake([MeetingBooked::class]);

    expect(fn () => ($this->book)('14:00'))->toThrow(MeetingSlotUnavailableException::class);

    Event::assertNotDispatched(MeetingBooked::class);
});

it('fires nothing when an outer transaction rolls back after the booking', function (): void {
    Event::fake([MeetingBooked::class]);

    expect(fn () => DB::transaction(function (): void {
        ($this->book)();

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(MeetingBooked::class);
    expect(SalesMeetingBooking::query()->count())->toBe(0);
});

it('keeps the booking when a listener throws', function (): void {
    Event::listen(MeetingBooked::class, function (): void {
        throw new RuntimeException('calendar is down');
    });

    $booking = ($this->book)()['booking'];

    expect(SalesMeetingBooking::query()->whereKey($booking->id)->exists())->toBeTrue();
});

it('fires MeetingBookingCancelled once, and not again for a second cancel', function (): void {
    $booking = ($this->book)()['booking'];
    Event::fake([MeetingBookingCancelled::class]);

    $booking->cancel();
    $booking->fresh()->cancel();
    $booking->cancel();

    Event::assertDispatchedTimes(MeetingBookingCancelled::class, 1);
    Event::assertDispatched(MeetingBookingCancelled::class, fn (MeetingBookingCancelled $event): bool => $event->booking->is($booking) && $event->uid === $booking->uid);
    expect($booking->fresh()->cancelled_at)->not->toBeNull();
});

it('fires MeetingBookingCancelled once when two copies of an active booking are cancelled', function (): void {
    $booking = ($this->book)()['booking'];
    $first = $booking->fresh();
    $second = $booking->fresh();
    Event::fake([MeetingBookingCancelled::class]);

    $first->cancel();
    Carbon::setTestNow(now()->addMinute());
    $second->cancel();

    Event::assertDispatchedTimes(MeetingBookingCancelled::class, 1);
    expect($first->cancelled_at)->not->toBeNull()
        ->and($second->cancelled_at?->equalTo($first->cancelled_at))->toBeTrue()
        ->and($booking->fresh()->cancelled_at->equalTo($first->cancelled_at))->toBeTrue();
});

it('does not fire MeetingBookingCancelled when the cancelling transaction rolls back', function (): void {
    $booking = ($this->book)()['booking'];
    Event::fake([MeetingBookingCancelled::class]);

    expect(fn () => DB::transaction(function () use ($booking): void {
        $booking->cancel();

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(MeetingBookingCancelled::class);
    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('refuses to delete a link with an upcoming active booking and keeps the booking', function (): void {
    ($this->book)();

    expect(fn () => $this->link->delete())->toThrow(MeetingLinkInUseException::class, 'This link has 1 upcoming booking.');

    expect(SalesMeetingLink::query()->whereKey($this->link->id)->exists())->toBeTrue()
        ->and(SalesMeetingBooking::query()->count())->toBe(1);
});

it('deletes a link whose bookings are only past or cancelled', function (): void {
    ['booking' => $upcoming] = ($this->book)();
    $upcoming->cancel();

    SalesMeetingBooking::query()->create([
        'uid' => (string) Str::uuid(),
        'meeting_link_id' => $this->link->id,
        'user_id' => $this->link->user_id,
        'invitee_name' => 'Past Invitee',
        'invitee_email' => 'past@example.com',
        'starts_at' => '2030-01-01 09:00:00',
        'ends_at' => '2030-01-01 09:30:00',
        'timezone' => 'UTC',
    ]);

    $this->link->delete();

    expect(SalesMeetingLink::query()->whereKey($this->link->id)->exists())->toBeFalse();
});
