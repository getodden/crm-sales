<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Exceptions\DealStageMismatchException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\PipelineStage;

class CreateDealAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DealStageMismatchException
     */
    public function execute(array $attributes, int|string|null $userId = null): Deal
    {
        $attributes = Arr::except($attributes, ['closed_at', 'lost_reason', 'lost_notes']);
        $attributes['owner_id'] ??= $userId;
        $attributes['status'] = DealStatus::Open->value;

        $this->assertOpenStageOfPipeline($attributes['pipeline_id'] ?? null, $attributes['stage_id'] ?? null);

        return DB::transaction(function () use ($attributes, $userId): Deal {
            $deal = Deal::create($attributes);

            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $deal->stage_id,
                'user_id' => $userId,
                'entered_at' => now(),
            ]);

            return $deal;
        });
    }

    private function assertOpenStageOfPipeline(mixed $pipelineId, mixed $stageId): void
    {
        $isOpenStageOfPipeline = PipelineStage::query()
            ->whereKey($stageId)
            ->where('pipeline_id', $pipelineId)
            ->where('is_closed_won', false)
            ->where('is_closed_lost', false)
            ->exists();

        if (! $isOpenStageOfPipeline) {
            throw DealStageMismatchException::notOpenStageOfPipeline();
        }
    }
}
