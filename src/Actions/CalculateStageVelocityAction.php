<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Collection;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\PipelineStage;

class CalculateStageVelocityAction
{
    /**
     * Calculate sales cycle duration and average dwell time per stage.
     *
     * @return array{
     *     average_sales_cycle_days: float,
     *     stages: array<int, array{
     *         stage_id: int,
     *         stage_name: string,
     *         probability: int,
     *         average_dwell_days: float,
     *         transition_count: int,
     *         stale_deal_count: int
     *     }>
     * }
     */
    public function execute(?int $pipelineId = null, ?int $teamId = null): array
    {
        $stagesQuery = PipelineStage::query()->orderBy('sort_order');

        if ($pipelineId !== null) {
            $stagesQuery->where('pipeline_id', $pipelineId);
        }

        $stages = $stagesQuery->get();

        $stageMetrics = [];

        // Dwell time and transitions for every stage in one grouped query (avg() leaves out rows still in the stage).
        $historyStats = DealStageHistory::query()
            ->whereIn('to_stage_id', $stages->modelKeys())
            ->when($teamId !== null, fn ($query) => $query->whereHas('deal', fn ($deal) => $deal->forTeam((int) $teamId)))
            ->toBase()
            ->select('to_stage_id')
            ->selectRaw('count(*) as transition_count, avg(duration_in_stage_seconds) as average_seconds')
            ->groupBy('to_stage_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->to_stage_id);

        foreach ($stages as $stage) {
            $stats = $historyStats->get($stage->id);
            $avgSeconds = $stats?->average_seconds !== null ? (float) $stats->average_seconds : null;
            $count = (int) ($stats->transition_count ?? 0);

            // Count open deals that have exceeded rot_after_days (stale deals)
            $staleCount = 0;
            if ($stage->rot_after_days !== null && $stage->rot_after_days > 0) {
                /** @var Collection<int, Deal> $activeDeals */
                $activeDeals = $stage->deals()->where('status', 'open')
                    ->when($teamId !== null, fn ($query) => $query->forTeam((int) $teamId))
                    ->with('stageHistory')
                    ->get();
                foreach ($activeDeals as $deal) {
                    if ($deal->daysInCurrentStage() >= $stage->rot_after_days) {
                        $staleCount++;
                    }
                }
            }

            $avgDays = $avgSeconds !== null ? round($avgSeconds / 86400, 1) : 0.0;

            $stageMetrics[$stage->id] = [
                'stage_id' => $stage->id,
                'stage_name' => $stage->name,
                'probability' => $stage->probability,
                'average_dwell_days' => $avgDays,
                'transition_count' => $count,
                'stale_deal_count' => $staleCount,
            ];
        }

        // Calculate average sales cycle for won deals
        $wonDealsQuery = Deal::query()->where('status', 'won')->whereNotNull('closed_at');

        if ($teamId !== null) {
            $wonDealsQuery->forTeam($teamId);
        }

        if ($pipelineId !== null) {
            $wonDealsQuery->where('pipeline_id', $pipelineId);
        }

        $wonDeals = $wonDealsQuery->get();
        $totalCycleDays = 0.0;
        $wonWithDates = 0;

        foreach ($wonDeals as $wonDeal) {
            if ($wonDeal->created_at !== null && $wonDeal->closed_at !== null) {
                $totalCycleDays += $wonDeal->created_at->diffInDays($wonDeal->closed_at);
                $wonWithDates++;
            }
        }

        $avgSalesCycle = $wonWithDates > 0 ? round($totalCycleDays / $wonWithDates, 1) : 0.0;

        return [
            'average_sales_cycle_days' => $avgSalesCycle,
            'stages' => $stageMetrics,
        ];
    }
}
