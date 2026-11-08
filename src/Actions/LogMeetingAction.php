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

class LogMeetingAction
{
    public function execute(Contact $contact, string $title, string $notes, int $durationMinutes, CarbonInterface $dueAt): Activity
    {
        return DB::transaction(function () use ($contact, $title, $notes, $durationMinutes, $dueAt): Activity {
            $activity = $contact->logActivity(
                type: ActivityType::Meeting,
                title: $title,
                body: $notes,
                metadata: ['duration_minutes' => $durationMinutes],
                dueAt: $dueAt,
                status: ActivityStatus::Pending,
            );

            if ($contact->lead_status === LeadStatus::New) {
                $contact->update(['lead_status' => LeadStatus::InProgress]);
            }

            return $activity;
        });
    }
}
