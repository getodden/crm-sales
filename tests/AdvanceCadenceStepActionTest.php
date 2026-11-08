<?php

declare(strict_types=1);

use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\AdvanceCadenceStepAction;
use Odden\Sales\Enums\CadenceStepOutcome;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

function enrollmentOn(Contact $contact, string $firstStepType, string $status = 'active'): SalesSequenceEnrollment
{
    $sequence = SalesSequence::query()->create([
        'name' => 'Outbound',
        'is_active' => true,
        'steps' => [
            ['step' => 1, 'type' => $firstStepType, 'delay_days' => 0, 'title' => 'First touch'],
            ['step' => 2, 'type' => 'task', 'delay_days' => 2, 'title' => 'Send deck'],
        ],
    ]);

    return SalesSequenceEnrollment::query()->create([
        'sequence_id' => $sequence->id,
        'contact_id' => $contact->id,
        'current_step' => 1,
        'status' => $status,
        'next_step_due_at' => now(),
        'enrolled_at' => now()->subDay(),
    ]);
}

it('claims a manual step, logs it and moves a new lead to in progress', function (): void {
    $contact = Contact::factory()->create(['lead_status' => LeadStatus::New, 'last_contacted_at' => null]);
    $enrollment = enrollmentOn($contact, 'call');

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    $activity = Activity::query()->where('subject_id', $contact->id)->sole();

    expect($outcome)->toBe(CadenceStepOutcome::Advanced)
        ->and($enrollment->refresh()->current_step)->toBe(2)
        ->and($activity->type)->toBe(ActivityType::Call)
        ->and($activity->status)->toBe(ActivityStatus::Completed)
        ->and($activity->metadata)->toBe(['sequence_enrollment_id' => $enrollment->id, 'step' => 1])
        ->and($contact->refresh()->lead_status)->toBe(LeadStatus::InProgress)
        ->and($contact->last_contacted_at)->not->toBeNull();
});

it('completes the pending task the processor created instead of logging a second activity', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'linkedin');
    $pending = $contact->logActivity(
        type: ActivityType::LinkedIn,
        title: 'LinkedIn: First touch',
        metadata: ['sequence_enrollment_id' => $enrollment->id, 'step' => 1],
        status: ActivityStatus::Pending,
    );

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    expect($outcome)->toBe(CadenceStepOutcome::Advanced)
        ->and($contact->refresh()->lead_status)->not->toBeNull()
        ->and($pending->refresh()->status)->toBe(ActivityStatus::Completed)
        ->and($pending->completed_at)->not->toBeNull()
        ->and(Activity::query()->count())->toBe(1);
});

it('does not send an email step', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'email');

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    expect($outcome)->toBe(CadenceStepOutcome::EmailStep)
        ->and($enrollment->refresh()->current_step)->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

it('does not advance an enrollment that is no longer active', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'call', 'unenrolled');

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    expect($outcome)->toBe(CadenceStepOutcome::NoLongerActive)
        ->and($enrollment->refresh()->current_step)->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

it('runs a step once when a second caller holds a stale enrollment', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'call');
    $stale = SalesSequenceEnrollment::query()->findOrFail($enrollment->id);

    $first = app(AdvanceCadenceStepAction::class)->execute($enrollment);
    $second = app(AdvanceCadenceStepAction::class)->execute($stale);

    expect($first)->toBe(CadenceStepOutcome::Advanced)
        ->and($second)->toBe(CadenceStepOutcome::AlreadyHandled)
        ->and(Activity::query()->count())->toBe(1);
});

it('reports no longer active when the contact has been deleted', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'call');
    $contact->delete();

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    expect($outcome)->toBe(CadenceStepOutcome::NoLongerActive)
        ->and($enrollment->refresh()->current_step)->toBe(1);
});

it('reports no longer active when the enrollment is past its last step', function (): void {
    $contact = Contact::factory()->create();
    $enrollment = enrollmentOn($contact, 'call');
    $enrollment->update(['current_step' => 3]);

    $outcome = app(AdvanceCadenceStepAction::class)->execute($enrollment);

    expect($outcome)->toBe(CadenceStepOutcome::NoLongerActive)
        ->and(Activity::query()->count())->toBe(0);
});
