<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\CompleteActivityAction;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Support\ActivityMarker;
use Odden\Sales\Enums\CadenceStepOutcome;
use Odden\Sales\Models\SalesSequenceEnrollment;

class AdvanceCadenceStepAction
{
    public function __construct(private readonly CompleteActivityAction $completeActivity) {}

    public function execute(SalesSequenceEnrollment $enrollment): CadenceStepOutcome
    {
        $enrollment->loadMissing('sequence');
        $contact = $enrollment->contact()->first();

        if ($contact === null) {
            return CadenceStepOutcome::NoLongerActive;
        }

        $step = $enrollment->current_step;
        $stepDef = ($enrollment->sequence->steps ?? [])[$step - 1] ?? null;

        if ($enrollment->status !== 'active' || $stepDef === null) {
            return CadenceStepOutcome::NoLongerActive;
        }

        if ($stepDef['type'] === 'email') {
            return CadenceStepOutcome::EmailStep;
        }

        $activityType = match ($stepDef['type']) {
            'call' => ActivityType::Call,
            'linkedin' => ActivityType::LinkedIn,
            default => ActivityType::Task,
        };

        $claimed = DB::transaction(function () use ($enrollment, $contact, $step, $stepDef, $activityType): bool {
            if (! $enrollment->claimStep($step)) {
                return false;
            }

            /** @var Activity|null $pending */
            $pending = Activity::query()
                ->where('subject_type', $contact->getMorphClass())
                ->where('subject_id', $contact->getKey())
                ->where('status', ActivityStatus::Pending)
                ->where('marker', ActivityMarker::cadenceStep($enrollment->id, $step))
                ->latest('id')
                ->first();

            if ($pending !== null) {
                $this->completeActivity->execute($pending);
            } else {
                $contact->logActivity(
                    type: $activityType,
                    title: "{$activityType->label()}: {$stepDef['title']}",
                    body: "Cadence [{$enrollment->sequence->name}] Step {$step}",
                    metadata: [
                        'sequence_enrollment_id' => $enrollment->id,
                        'step' => $step,
                    ],
                    status: ActivityStatus::Completed,
                );
            }

            return true;
        });

        if (! $claimed) {
            return CadenceStepOutcome::AlreadyHandled;
        }

        $contact->markContacted();
        if ($contact->lead_status === LeadStatus::New) {
            $contact->update(['lead_status' => LeadStatus::InProgress]);
        }

        return CadenceStepOutcome::Advanced;
    }
}
