<?php

declare(strict_types=1);

use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\LogCallAction;
use Odden\Sales\Actions\LogMeetingAction;
use Odden\Sales\Actions\LogProspectingTouchAction;
use Odden\Sales\Enums\CallDisposition;

it('logs a prospecting touch, marks the contact contacted and moves a new lead to attempted contact', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::New, 'last_contacted_at' => null]);

    $activity = app(LogProspectingTouchAction::class)->execute($contact, ActivityType::LinkedIn);

    expect($activity->type)->toBe(ActivityType::LinkedIn)
        ->and($activity->status)->toBe(ActivityStatus::Completed)
        ->and($activity->title)->toBe('Outbound LinkedIn / Social Touch')
        ->and($contact->refresh()->lead_status)->toBe(LeadStatus::AttemptedContact)
        ->and($contact->last_contacted_at)->not->toBeNull();
});

it('leaves a lead that has moved past new where it is on a prospecting touch', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::Connected]);

    app(LogProspectingTouchAction::class)->execute($contact, ActivityType::Email);

    expect($contact->refresh()->lead_status)->toBe(LeadStatus::Connected);
});

it('logs a connected call with its duration and sets the lead to connected', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

    $activity = app(LogCallAction::class)->execute($contact, CallDisposition::Connected, 12, 'Wants a demo');

    expect($activity->title)->toBe('Outbound Call (Connected)')
        ->and($activity->body)->toBe('Wants a demo')
        ->and($activity->metadata)->toBe(['disposition' => 'connected', 'duration_minutes' => 12])
        ->and($contact->refresh()->lead_status)->toBe(LeadStatus::Connected)
        ->and(Activity::query()->count())->toBe(1);
});

it('describes a call without notes by its outcome and only attempts contact on a missed call', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

    $activity = app(LogCallAction::class)->execute($contact, CallDisposition::NoAnswer, 0);

    expect($activity->body)->toBe('Logged call outcome: No Answer.')
        ->and($contact->refresh()->lead_status)->toBe(LeadStatus::AttemptedContact);
});

it('schedules a pending follow-up task only when a due date is given', function (): void {
    $contact = Contact::factory()->create();
    $dueAt = now()->addDay()->startOfDay();

    app(LogCallAction::class)->execute($contact, CallDisposition::Busy, 1, '', $dueAt, '');
    app(LogCallAction::class)->execute($contact, CallDisposition::Busy, 1);

    $tasks = Activity::query()->where('type', ActivityType::Task->value)->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->title)->toBe('Follow-up Call')
        ->and($tasks[0]->status)->toBe(ActivityStatus::Pending)
        ->and($tasks[0]->due_at->equalTo($dueAt))->toBeTrue();
});

it('uses the given follow-up title', function (): void {
    $contact = Contact::factory()->create();

    app(LogCallAction::class)->execute($contact, CallDisposition::Busy, 1, '', now()->addDay(), 'Send the proposal');

    expect(Activity::query()->where('type', ActivityType::Task->value)->value('title'))->toBe('Send the proposal');
});

it('schedules a pending meeting and moves a new lead to in progress', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);
    $dueAt = now()->addDays(2)->setTime(10, 0);

    $activity = app(LogMeetingAction::class)->execute($contact, 'Demo', 'Bring the deck', 45, $dueAt);

    expect($activity->type)->toBe(ActivityType::Meeting)
        ->and($activity->status)->toBe(ActivityStatus::Pending)
        ->and($activity->body)->toBe('Bring the deck')
        ->and($activity->metadata)->toBe(['duration_minutes' => 45])
        ->and($activity->due_at->equalTo($dueAt))->toBeTrue()
        ->and($contact->refresh()->lead_status)->toBe(LeadStatus::InProgress);
});

it('keeps the lead status of a contact already past new when a meeting is scheduled', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::Connected]);

    app(LogMeetingAction::class)->execute($contact, 'Demo', '', 30, now()->addDay());

    expect($contact->refresh()->lead_status)->toBe(LeadStatus::Connected);
});
