<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class EnrollContactInSequenceAction
{
    /**
     * Enroll a contact into an outbound sales sequence.
     */
    public function execute(Contact $contact, SalesSequence $sequence, int|string|null $enrolledById = null): SalesSequenceEnrollment
    {
        $steps = $sequence->steps ?? [];
        $firstStep = $steps[0] ?? null;
        $delayDays = $firstStep !== null ? $firstStep['delay_days'] : 0;

        /** @var SalesSequenceEnrollment $enrollment */
        $enrollment = SalesSequenceEnrollment::updateOrCreate(
            [
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
            ],
            [
                'current_step' => 1,
                'status' => 'active',
                'next_step_due_at' => now()->addDays($delayDays)->toDateString(),
                'enrolled_by_id' => $enrolledById ?? auth()->id(),
                'enrolled_at' => now(),
            ]
        );

        if ($contact->lead_status === LeadStatus::New) {
            $contact->updateQuietly([
                'lead_status' => LeadStatus::InProgress,
            ]);
        }

        return $enrollment;
    }
}
