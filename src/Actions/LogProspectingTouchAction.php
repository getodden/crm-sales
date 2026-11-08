<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;

class LogProspectingTouchAction
{
    public function execute(Contact $contact, ActivityType $type): Activity
    {
        return DB::transaction(function () use ($contact, $type): Activity {
            $activity = $contact->logActivity(
                type: $type,
                title: "Outbound {$type->label()} Touch",
                body: 'Completed prospecting outreach via Sales Cockpit.',
                status: ActivityStatus::Completed,
            );

            $contact->markContacted();

            if ($contact->lead_status === LeadStatus::New) {
                $contact->update(['lead_status' => LeadStatus::AttemptedContact]);
            }

            return $activity;
        });
    }
}
