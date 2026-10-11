<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class StopSequencesOnReplyAction
{
    /**
     * Take a contact out of every sequence they are still in, because they wrote back.
     *
     * Each stopped enrollment is unenrolled (no later step runs) and the stop is logged on the contact's timeline,
     * naming the sequence. Completed and already unenrolled enrollments are left alone. The caller decides what
     * counts as a reply (an inbound email from the contact, for example); this only acts on the contact.
     *
     * @return int The number of enrollments stopped.
     */
    public function execute(Contact $contact): int
    {
        $enrollments = SalesSequenceEnrollment::query()
            ->with('sequence')
            ->where('contact_id', $contact->getKey())
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $enrollment->update(['status' => 'unenrolled', 'next_step_due_at' => null]);

            $sequence = $enrollment->getRelation('sequence');
            $name = $sequence instanceof SalesSequence ? $sequence->name : 'a sequence';

            $contact->logActivity(
                type: ActivityType::Note,
                title: "Sequence stopped: {$name}",
                body: "{$contact->full_name} replied, so the rest of the sequence will not be sent.",
                metadata: ['enrollment_id' => $enrollment->getKey(), 'sequence_id' => $enrollment->sequence_id, 'reason' => 'reply'],
            );
        }

        return $enrollments->count();
    }
}
