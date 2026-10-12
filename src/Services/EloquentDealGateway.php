<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\DealOutcome;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\DealSnapshot;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Support\Money;

class EloquentDealGateway implements DealGateway
{
    /**
     * With no $pipelineId the default active pipeline is used (or, if none is marked default, the first active
     * one), and with no $stageId the pipeline's first open stage. A $stageId outside the pipeline, or a closed
     * (won or lost) stage, is not used: the deal is created open, so it cannot start in a closed stage.
     */
    public function createOpenDeal(
        Contact $contact,
        string $name,
        float $amount,
        ?int $pipelineId = null,
        ?int $stageId = null,
        ?int $ownerId = null,
        bool $associateCompany = false,
    ): ?DealSnapshot {
        $pipeline = $pipelineId !== null
            ? Pipeline::query()->find($pipelineId)
            : Pipeline::query()->active()->orderByDesc('is_default')->orderBy('id')->first();

        if ($pipeline === null) {
            return null;
        }

        $stage = $stageId !== null
            ? $pipeline->stages()->whereKey($stageId)->where('is_closed_won', false)->where('is_closed_lost', false)->first()
            : $pipeline->defaultStage();

        if ($stage === null) {
            return null;
        }

        $deal = DB::transaction(function () use ($contact, $name, $amount, $pipeline, $stage, $ownerId, $associateCompany): Deal {
            $deal = Deal::create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'name' => $name,
                'amount' => $amount,
                'currency' => Money::defaultCurrency(),
                'status' => DealStatus::Open,
                'owner_id' => $ownerId ?? $contact->owner_id,
                'team_id' => $contact->team_id,
            ]);

            // The deal's first stage, so its dwell time is measured like any other stage's.
            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $stage->id,
                'entered_at' => now(),
            ]);

            // The deal is the parent: that is the direction Deal::contacts() and Contact::deals() read.
            $deal->associateWith($contact, 'primary');

            if ($associateCompany) {
                /** @var Company|null $company */
                $company = $contact->companies()->first();

                if ($company !== null) {
                    $deal->associateWith($company, 'primary');
                }
            }

            return $deal;
        });

        return $this->snapshot($deal);
    }

    public function findMany(array $ids): Collection
    {
        return Deal::query()->whereIn('id', $ids)->get()->map(fn (Deal $deal): DealSnapshot => $this->snapshot($deal));
    }

    private function snapshot(Deal $deal): DealSnapshot
    {
        return new DealSnapshot(
            id: (int) $deal->id,
            name: (string) $deal->name,
            status: DealOutcome::from($deal->status->value),
            amount: (float) $deal->amount,
            pipelineId: (int) $deal->pipeline_id,
            stageId: (int) $deal->stage_id,
            createdAt: $deal->created_at,
            closedAt: $deal->closed_at,
        );
    }
}
