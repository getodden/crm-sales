<?php

declare(strict_types=1);

use Odden\Core\Models\Contact;
use Odden\Sales\Actions\UnenrollFromSequenceAction;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

it('stops an active enrollment and clears the next step', function (): void {
    $sequence = SalesSequence::create([
        'name' => 'Outbound',
        'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
        'is_active' => true,
    ]);
    $enrollment = SalesSequenceEnrollment::create([
        'sequence_id' => $sequence->id,
        'contact_id' => Contact::factory()->create()->id,
        'status' => 'active',
        'next_step_due_at' => now()->addDay(),
    ]);

    $result = app(UnenrollFromSequenceAction::class)->execute($enrollment);

    expect($result->is($enrollment))->toBeTrue()
        ->and($enrollment->fresh()->status)->toBe('unenrolled')
        ->and($enrollment->fresh()->next_step_due_at)->toBeNull();
});
