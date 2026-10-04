<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

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
     * Move a deal to a new stage, update history and status, and fire events.
     */
    public function execute(Deal $deal, PipelineStage $toStage, int|string|null $userId = null, ?string $lostReason = null, ?string $lostNotes = null): Deal
    {
        return DB::transaction(function () use ($deal, $toStage, $userId, $lostReason, $lostNotes): Deal {
            $fromStage = $deal->stage;
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

            if ($lostReason !== null) {
                $deal->lost_reason = $lostReason;
            } elseif ($toStage->is_closed_won || ! $toStage->is_closed_lost) {
                $deal->lost_reason = null;
            }

            if ($lostNotes !== null) {
                $deal->lost_notes = $lostNotes;
            } elseif ($toStage->is_closed_won || ! $toStage->is_closed_lost) {
                $deal->lost_notes = null;
            }

            $deal->save();

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

            // Auto-unenroll associated contacts from active outbound cadences on deal closure
            if ($newStatus === DealStatus::Won || $newStatus === DealStatus::Lost) {
                $contactIds = $deal->contacts()->pluck((new Contact)->getQualifiedKeyName())->all();
                if (! empty($contactIds)) {
                    SalesSequenceEnrollment::query()
                        ->whereIn('contact_id', $contactIds)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'unenrolled',
                            'next_step_due_at' => null,
                        ]);
                }
            }

            return $deal;
        });
    }
}
