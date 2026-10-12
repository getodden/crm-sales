<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Events\DealLost;
use Odden\Sales\Events\DealMovedStage;
use Odden\Sales\Events\DealWon;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\SalesSequenceEnrollment;

class ChangeDealStageAction
{
    /**
     * The deal columns a stage move reads and writes.
     */
    private const STAGE_ATTRIBUTES = ['pipeline_id', 'stage_id', 'status', 'closed_at', 'lost_reason', 'lost_notes'];

    /**
     * Move a deal to a new stage, update history and status, and fire events.
     */
    public function execute(Deal $deal, PipelineStage $toStage, int|string|null $userId = null, ?string $lostReason = null, ?string $lostNotes = null): Deal
    {
        return DB::transaction(function () use ($deal, $toStage, $userId, $lostReason, $lostNotes): Deal {
            // Lock the deal row so concurrent moves run one after the other, and take the stage it is in now from
            // that row: the caller's copy (and its cached stage relation) may be stale.
            /** @var Deal $locked */
            $locked = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
            $current = Arr::only($locked->getAttributes(), self::STAGE_ATTRIBUTES);
            $deal->setRawAttributes(array_merge($deal->getAttributes(), $current));
            $deal->syncOriginalAttributes(self::STAGE_ATTRIBUTES);

            /** @var PipelineStage $fromStage */
            $fromStage = $deal->relationLoaded('stage') && $deal->stage->id === $locked->stage_id
                ? $deal->stage
                : $locked->stage;
            $deal->setRelation('stage', $fromStage);
            $now = now();

            // Already in that stage: nothing moves, so no automations run, no history row is added and no event fires.
            if ($fromStage->id === $toStage->id) {
                return $deal;
            }

            // Run automation guards and actions
            app(ExecuteStageAutomationsAction::class)->execute($deal, $toStage, $userId);

            // 1. Close prior stage history record if exists
            /** @var DealStageHistory|null $lastHistory */
            $lastHistory = DealStageHistory::query()
                ->where('deal_id', $deal->id)
                ->whereNull('exited_at')
                ->latest('entered_at')
                ->first();

            if ($lastHistory !== null) {
                $duration = (int) $lastHistory->entered_at->diffInSeconds($now);
                $lastHistory->update([
                    'exited_at' => $now,
                    'duration_in_stage_seconds' => $duration,
                ]);
            } elseif (! DealStageHistory::query()->where('deal_id', $deal->id)->exists()) {
                // A deal created without a first history row (outside the panel): it has been in its stage since it
                // was created, so record that stay rather than leaving the first stage's dwell time out.
                $enteredAt = $deal->created_at ?? $now;
                DealStageHistory::create([
                    'deal_id' => $deal->id,
                    'from_stage_id' => null,
                    'to_stage_id' => $fromStage->id,
                    'entered_at' => $enteredAt,
                    'exited_at' => $now,
                    'duration_in_stage_seconds' => (int) $enteredAt->diffInSeconds($now),
                ]);
            }

            // 2. Determine new status and close dates
            $newStatus = DealStatus::Open;
            $closedAt = null;

            if ($toStage->is_closed_won) {
                $newStatus = DealStatus::Won;
                $closedAt = $now;
            } elseif ($toStage->is_closed_lost) {
                $newStatus = DealStatus::Lost;
                $closedAt = $now;
            }

            // 3. Update deal record
            $deal->pipeline_id = $toStage->pipeline_id;
            $deal->stage_id = $toStage->id;
            $deal->status = $newStatus;
            $deal->closed_at = $closedAt;

            // A lost reason only belongs on a lost deal; without a new one a lost deal keeps the one it has.
            if (! $toStage->is_closed_lost) {
                $deal->lost_reason = null;
                $deal->lost_notes = null;
            } else {
                if ($lostReason !== null) {
                    $deal->lost_reason = $lostReason;
                }

                if ($lostNotes !== null) {
                    $deal->lost_notes = $lostNotes;
                }
            }

            $deal->save();
            $deal->setRelation('stage', $toStage);

            if ($fromStage->pipeline_id !== $toStage->pipeline_id) {
                $deal->unsetRelation('pipeline');
            }

            // 4. Record new stage history entry
            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => $fromStage->id,
                'to_stage_id' => $toStage->id,
                'user_id' => $userId ?? auth()->id(),
                'entered_at' => $now,
            ]);

            // 5. Fire domain events
            event(new DealMovedStage($deal, $fromStage, $toStage, $userId));

            if ($newStatus === DealStatus::Won) {
                event(new DealWon($deal, $userId));
            } elseif ($newStatus === DealStatus::Lost) {
                event(new DealLost($deal, $lostReason, $userId));
            }

            if ($newStatus === DealStatus::Won || $newStatus === DealStatus::Lost) {
                $this->stopProspectingCadences($deal);
            }

            return $deal;
        });
    }

    /**
     * Unenroll the deal's contacts from their active cadences now that the deal is closed. Contacts who still
     * have another open deal keep theirs, and so do cadences begun after the contact became a customer
     * (onboarding and the like): those are not about this deal.
     */
    protected function stopProspectingCadences(Deal $deal): void
    {
        $contactIds = $deal->contacts()->pluck((new Contact)->getQualifiedKeyName())->all();

        if (empty($contactIds)) {
            return;
        }

        $stillInPlay = DB::table(config('odden-core.tables.associations', 'odden_associations'))
            ->where('parent_type', $deal->getMorphClass())
            ->where('child_type', (new Contact)->getMorphClass())
            ->whereIn('child_id', $contactIds)
            ->whereIn('parent_id', Deal::query()->open()->whereKeyNot($deal->getKey())->select('id'))
            ->pluck('child_id')
            ->all();

        $enrollmentIds = SalesSequenceEnrollment::query()
            ->whereIn('contact_id', array_diff($contactIds, $stillInPlay))
            ->where('status', 'active')
            ->with('contact')
            ->get()
            ->reject(fn (SalesSequenceEnrollment $enrollment): bool => $enrollment->beganAfterContactBecameCustomer())
            ->modelKeys();

        if ($enrollmentIds !== []) {
            SalesSequenceEnrollment::query()->whereKey($enrollmentIds)->update([
                'status' => 'unenrolled',
                'next_step_due_at' => null,
            ]);
        }
    }
}
