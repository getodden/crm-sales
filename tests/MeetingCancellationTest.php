<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Exceptions\MeetingLinkInUseException;
use Odden\Sales\Mail\MeetingBookedMail;
use Odden\Sales\Mail\MeetingCancelledMail;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingInvite;
use Odden\Sales\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2030-01-06 08:00:00', 'UTC'));
    Mail::fake();

    $host = User::factory()->create(['name' => 'Beth Caldwell', 'email' => 'beth@odden.test']);

    $this->link = SalesMeetingLink::query()->create([
        'user_id' => $host->id,
        'slug' => 'beth-cancel',
        'title' => 'Discovery call',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-10:30']],
        'is_active' => true,
    ]);

    $this->booking = app(BookMeetingAction::class)
        ->execute($this->link, 'Dana Scully', 'dana@example.com', Carbon::parse('2030-01-07 09:30'))['booking'];

    $this->cancelUrl = fn (?SalesMeetingBooking $booking = null): string => URL::signedRoute('odden.meetings.cancel.show', ['uid' => ($booking ?? $this->booking)->uid]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('emails the invitee and the host a CANCEL invite with the same uid when a booking is cancelled', function (): void {
    $this->booking->cancel(MeetingCancelledBy::Staff, 'Beth Caldwell', 'Out sick');

    Mail::assertQueued(MeetingCancelledMail::class, 2);
    Mail::assertQueued(MeetingCancelledMail::class, function (MeetingCancelledMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('dana@example.com')
            && $mail->recipientRole === 'invitee'
            && str_contains($html, 'cancelled by Beth Caldwell')
            && str_contains($html, 'Out sick')
            && str_contains($html, 'Monday, January 7, 2030');
    });
    Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->hasTo('beth@odden.test') && $mail->recipientRole === 'host');

    $ics = app(MeetingInvite::class)->cancelIcs($this->booking);

    expect($ics)->toContain('METHOD:CANCEL')
        ->toContain("UID:{$this->booking->uid}@getodden")
        ->toContain('STATUS:CANCELLED')
        ->toContain('SEQUENCE:1')
        ->and(app(MeetingInvite::class)->ics($this->booking))->toContain('SEQUENCE:0')->toContain("UID:{$this->booking->uid}@getodden");
});

it('names the invitee as the canceller in both emails when the invitee cancels', function (): void {
    $this->booking->cancel(MeetingCancelledBy::Invitee, 'Dana Scully');

    Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->recipientRole === 'host' && str_contains($mail->render(), 'cancelled by Dana Scully'));
    Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->recipientRole === 'invitee' && str_contains($mail->render(), 'cancelled by you'));
});

it('sends nothing for a second cancel', function (): void {
    $this->booking->cancel();
    $this->booking->fresh()->cancel();
    $this->booking->cancel();

    Mail::assertQueued(MeetingCancelledMail::class, 2);
});

it('puts a signed cancel link in the invitee confirmation only', function (): void {
    $invitee = (new MeetingBookedMail($this->booking, 'invitee'))->render();
    $host = (new MeetingBookedMail($this->booking, 'host'))->render();

    expect($invitee)->toContain('Cancel this meeting')->toContain("/meet/bookings/{$this->booking->uid}/cancel?signature=")
        ->and($host)->not->toContain('Cancel this meeting');
});

it('builds the cancel link with the host the booking was made on, not the queue worker URL', function (): void {
    URL::forceRootUrl('http://book.odden.test');
    $mail = new MeetingBookedMail($this->booking, 'invitee');

    URL::forceRootUrl('http://worker.odden.test');
    $html = unserialize(serialize($mail))->render();
    URL::forceRootUrl(null);

    expect($mail->cancelUrl)->toStartWith("http://book.odden.test/meet/bookings/{$this->booking->uid}/cancel?signature=")
        ->and($html)->toContain(e($mail->cancelUrl));

    $this->get((string) $mail->cancelUrl)->assertOk()->assertSee('Cancel this meeting');
});

it('still sends the cancellation emails when the link and its bookings are deleted before the queue runs them', function (): void {
    DB::transaction(function (): void {
        $this->booking->cancel(MeetingCancelledBy::Staff, 'Beth Caldwell', 'Out sick');
        SalesMeetingBooking::query()->whereKey($this->booking->id)->delete();
        SalesMeetingLink::query()->whereKey($this->link->id)->delete();
    });

    $queued = Mail::queued(MeetingCancelledMail::class);
    expect($queued)->toHaveCount(2);

    foreach ($queued as $mail) {
        $sent = unserialize(serialize($mail));

        expect($sent->render())->toContain('Discovery call')->toContain('Out sick')
            ->and(app(MeetingInvite::class)->cancelIcs($sent->booking))->toContain('METHOD:CANCEL')->toContain("UID:{$this->booking->uid}@getodden");
    }
});

it('cancels a published invite by publishing it again when the host has no email address', function (): void {
    $this->booking->user->forceFill(['email' => ''])->save();
    $booking = $this->booking->fresh();

    expect(app(MeetingInvite::class)->ics($booking))->toContain('METHOD:PUBLISH')
        ->and(app(MeetingInvite::class)->cancelIcs($booking))->toContain('METHOD:PUBLISH')->toContain('STATUS:CANCELLED')->toContain('SEQUENCE:1')->not->toContain('ORGANIZER');
});

it('shows the cancel page on GET without cancelling anything', function (): void {
    Event::fake([MeetingBookingCancelled::class]);

    $this->get(($this->cancelUrl)())
        ->assertOk()
        ->assertSee('Discovery call')
        ->assertSee('Cancel this meeting');

    Event::assertNotDispatched(MeetingBookingCancelled::class);
    expect($this->booking->fresh()->cancelled_at)->toBeNull();
});

it('cancels on POST and sends the emails with the reason', function (): void {
    $this->post(($this->cancelUrl)(), ['reason' => 'Plans changed'])->assertRedirect(($this->cancelUrl)());

    expect($this->booking->fresh()->cancelled_at)->not->toBeNull();
    Mail::assertQueued(MeetingCancelledMail::class, 2);
    Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->recipientRole === 'host' && str_contains($mail->render(), 'Plans changed'));

    $this->get(($this->cancelUrl)())->assertOk()->assertSee('has been cancelled');
});

it('sends nothing more when the invitee posts twice', function (): void {
    $this->post(($this->cancelUrl)());
    $this->post(($this->cancelUrl)());

    Mail::assertQueued(MeetingCancelledMail::class, 2);
});

it('returns 404 for an unsigned, forged, or unknown link', function (): void {
    $this->get("/meet/bookings/{$this->booking->uid}/cancel")->assertNotFound();
    $this->post("/meet/bookings/{$this->booking->uid}/cancel")->assertNotFound();
    $this->get(str_replace($this->booking->uid, 'not-the-uid', ($this->cancelUrl)()))->assertNotFound();
    $this->post(($this->cancelUrl)().'0')->assertNotFound();

    $other = URL::signedRoute('odden.meetings.cancel.show', ['uid' => (string) Str::uuid()]);
    $this->get($other)->assertNotFound();

    expect($this->booking->fresh()->cancelled_at)->toBeNull();
    Mail::assertNotQueued(MeetingCancelledMail::class);
});

it('does not let the invitee cancel a meeting that has started', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-01-07 09:45', 'UTC'));

    $this->get(($this->cancelUrl)())->assertOk()->assertSee('can no longer be cancelled');
    $this->post(($this->cancelUrl)())->assertRedirect();

    expect($this->booking->fresh()->cancelled_at)->toBeNull();
    Mail::assertNotQueued(MeetingCancelledMail::class);
});

it('points at cancelling when a link with an upcoming booking is deleted, and allows it once cancelled', function (): void {
    expect(fn () => $this->link->delete())->toThrow(MeetingLinkInUseException::class, 'Cancel them');

    $this->booking->cancel();

    expect($this->link->delete())->toBeTrue();
});
