<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Enums\CallDisposition;

class LogCallAction
{
    public function execute(
        Contact $contact,
        CallDisposition $disposition,
        int $durationMinutes,
        string $notes = '',
        ?CarbonInterface $followUpDueAt = null,
        string $followUpTitle = ''
    ): Activity {
        return DB::transaction(function () use ($contact, $disposition, $durationMinutes, $notes, $followUpDueAt, $followUpTitle): Activity {
            $activity = $contact->logActivity(
                type: ActivityType::Call,
                title: "Outbound Call ({$disposition->label()})",
                body: $notes !== '' ? $notes : "Logged call outcome: {$disposition->label()}.",
                metadata: [
                    'disposition' => $disposition->value,
                    'duration_minutes' => $durationMinutes,
                ],
                status: ActivityStatus::Completed,
            );

            $contact->markContacted();

            if ($disposition === CallDisposition::Connected) {
                $contact->update(['lead_status' => LeadStatus::Connected]);
            } elseif ($contact->lead_status === LeadStatus::New) {
                $contact->update(['lead_status' => LeadStatus::AttemptedContact]);
            }

            if ($followUpDueAt !== null) {
                $contact->logActivity(
                    type: ActivityType::Task,
                    title: $followUpTitle !== '' ? $followUpTitle : 'Follow-up Call',
                    dueAt: $followUpDueAt,
                    status: ActivityStatus::Pending,
                );
            }

            return $activity;
        });
    }
}
