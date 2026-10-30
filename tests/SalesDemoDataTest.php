<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\DemoRecord;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;
use Odden\Sales\Tests\Fixtures\User;

it('adds a pipeline, deals, templates, cadences and quotes to the sample data', function (): void {
    $this->artisan('odden:demo')->assertSuccessful();

    expect(Pipeline::query()->count())->toBe(1)
        ->and(Deal::query()->count())->toBe(8)
        ->and(Quote::query()->count())->toBe(5)
        ->and(SalesEmailTemplate::query()->count())->toBe(6)
        ->and(SalesSequence::query()->count())->toBe(3)
        ->and(SalesSequenceEnrollment::query()->count())->toBe(5);
});

it('does not make a second default pipeline when you already have one', function (): void {
    Pipeline::query()->create(['name' => 'Mine', 'code' => 'mine', 'is_default' => true, 'is_active' => true]);

    $this->artisan('odden:demo')->assertSuccessful();

    expect(Pipeline::query()->where('is_default', true)->pluck('name')->all())->toBe(['Mine']);
});

it('removes the sales sample data and leaves your own deals', function (): void {
    $pipeline = Pipeline::query()->create(['name' => 'Mine', 'code' => 'mine', 'is_default' => true, 'is_active' => true]);
    $stage = $pipeline->stages()->create(['name' => 'Open', 'code' => 'open', 'probability' => 10, 'sort_order' => 1]);
    $deal = Deal::query()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'name' => 'My deal', 'amount' => 100, 'currency' => 'USD']);

    $this->artisan('odden:demo')->assertSuccessful();
    $this->artisan('odden:demo', ['--remove' => true])->assertSuccessful();

    expect(Deal::query()->pluck('name')->all())->toBe(['My deal'])
        ->and(Pipeline::query()->count())->toBe(1)
        ->and(Quote::query()->count())->toBe(0)
        ->and(SalesSequence::query()->count())->toBe(0)
        ->and($deal->fresh())->not->toBeNull();
});

it('never emails a sample contact when the cadences run', function (): void {
    Mail::fake();
    $this->artisan('odden:demo')->assertSuccessful();

    SalesSequenceEnrollment::query()->update(['status' => 'active', 'next_step_due_at' => now()->subDay()->toDateString()]);

    app(ProcessCadencesAction::class)->execute();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(Contact::query()->count())->toBe(8);

    $this->artisan('odden:demo', ['--remove' => true])->assertSuccessful();

    expect(Activity::query()->count())->toBe(0);
});

it('keeps the sample contacts marked', function (): void {
    $this->artisan('odden:demo')->assertSuccessful();

    expect(DemoRecord::isDemo(Contact::query()->firstOrFail()))->toBeTrue();
});

it('removes the sample booking page even when someone has booked it, cancelling the upcoming booking first', function (): void {
    Event::fake([MeetingBookingCancelled::class]);
    User::factory()->create();
    $this->artisan('odden:demo')->assertSuccessful();

    $link = SalesMeetingLink::query()->where('slug', 'sample-intro-call')->firstOrFail();
    SalesMeetingBooking::query()->create([
        'uid' => (string) Str::uuid(),
        'meeting_link_id' => $link->id,
        'user_id' => $link->user_id,
        'invitee_name' => 'Dana',
        'invitee_email' => 'dana@example.com',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'timezone' => 'UTC',
    ]);

    $this->artisan('odden:demo', ['--remove' => true])->assertSuccessful();

    expect(SalesMeetingLink::query()->count())->toBe(0)
        ->and(SalesMeetingBooking::query()->active()->count())->toBe(0);
    Event::assertDispatchedTimes(MeetingBookingCancelled::class, 1);
});
