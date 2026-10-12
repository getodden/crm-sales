<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Support\Money;

class CalculatePipelineForecastAction
{
    /**
     * Calculate pipeline sales forecast and conversion metrics, optionally for one pipeline and/or one team's deals.
     *
     * `$visibleDeals` limits the figures to deals in the given query (for example the deals a panel
     * user may see); without it every deal is counted.
     *
     * Amounts are never added across currencies. The figures cover one currency, `currency` in the
     * result: `$currency` when given, otherwise the default currency (or, when no deal uses it, the
     * most-used one). Deals in any other currency are listed separately under `other_currencies`.
     *
     * @param  Builder<Deal>|null  $visibleDeals
     * @return array{
     *     currency: string,
     *     other_currencies: array<string, array{open_count: int, open_value: float, won_count: int, won_value: float, lost_count: int}>,
     *     open_value: float,
     *     open_count: int,
     *     weighted_forecast: float,
     *     won_value: float,
     *     won_count: int,
     *     lost_count: int,
     *     win_rate: float,
     *     average_deal_size: float,
     *     lost_reasons: array<string, int>,
     *     stale_deals_count: int
     * }
     */
    public function execute(?int $pipelineId = null, ?int $teamId = null, ?Builder $visibleDeals = null, ?string $currency = null): array
    {
        $dealsTable = config('odden-sales.tables.deals', 'odden_deals');
        $stagesTable = config('odden-sales.tables.stages', 'odden_pipeline_stages');

        $baseQuery = Deal::query();
        if ($visibleDeals !== null) {
            $baseQuery->whereIn("{$dealsTable}.id", $visibleDeals->select("{$dealsTable}.id"));
        }
        if ($pipelineId !== null) {
            $baseQuery->where("{$dealsTable}.pipeline_id", $pipelineId);
        }
        if ($teamId !== null) {
            $baseQuery->where("{$dealsTable}.team_id", $teamId);
        }

        $currency = $currency !== null && trim($currency) !== ''
            ? strtoupper(trim($currency))
            : Money::reportingCurrency($baseQuery, "{$dealsTable}.currency");

        $otherCurrencies = $this->otherCurrencies(clone $baseQuery, $currency, $dealsTable);
        $baseQuery->where("{$dealsTable}.currency", $currency);

        // 1. Open deals and value
        $openQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Open->value);
        $openCount = $openQuery->count();
        $openValue = (float) $openQuery->sum("{$dealsTable}.amount");

        // 2. Weighted forecast value based on stage win probability
        $openDeals = (clone $baseQuery)
            ->where('status', DealStatus::Open->value)
            ->with(['stage', 'stageHistory'])
            ->get();

        $weightedForecast = (float) $openDeals->sum(
            fn (Deal $deal): float => (float) $deal->amount * (($deal->stage->probability ?? 0) / 100.0)
        );

        // 3. Won deals
        $wonQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Won->value);
        $wonCount = $wonQuery->count();
        $wonValue = (float) $wonQuery->sum("{$dealsTable}.amount");

        // 4. Lost deals & Win rate
        $lostQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Lost->value);
        $lostCount = $lostQuery->count();

        $closedCount = $wonCount + $lostCount;
        $winRate = $closedCount > 0 ? round(($wonCount / $closedCount) * 100, 1) : 0.0;

        // 5. Average deal size
        $averageDealSize = (float) ($baseQuery->avg("{$dealsTable}.amount") ?? 0.0);

        // 6. Lost reasons breakdown
        $lostReasons = [];
        /** @var Collection<int, Deal> $lostDeals */
        $lostDeals = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Lost->value)->get();
        foreach ($lostDeals as $lostDeal) {
            $reason = $lostDeal->lost_reason ?: 'unspecified';
            $lostReasons[$reason] = ($lostReasons[$reason] ?? 0) + 1;
        }

        // 7. Stale deals count
        $staleCount = 0;
        foreach ($openDeals as $openDeal) {
            if ($openDeal->isRotten()) {
                $staleCount++;
            }
        }

        return [
            'currency' => $currency,
            'other_currencies' => $otherCurrencies,
            'open_value' => round($openValue, 2),
            'open_count' => $openCount,
            'weighted_forecast' => round($weightedForecast, 2),
            'won_value' => round($wonValue, 2),
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'win_rate' => $winRate,
            'average_deal_size' => round($averageDealSize, 2),
            'lost_reasons' => $lostReasons,
            'stale_deals_count' => $staleCount,
        ];
    }

    /**
     * Counts and values of deals not in the reporting currency, per currency.
     *
     * @param  Builder<Deal>  $query
     * @return array<string, array{open_count: int, open_value: float, won_count: int, won_value: float, lost_count: int}>
     */
    protected function otherCurrencies(Builder $query, string $currency, string $dealsTable): array
    {
        $rows = $query
            ->where("{$dealsTable}.currency", '!=', $currency)
            ->reorder()
            ->toBase()
            ->select(["{$dealsTable}.currency as currency", "{$dealsTable}.status as status"])
            ->selectRaw("count(*) as deal_count, sum({$dealsTable}.amount) as deal_amount")
            ->groupBy("{$dealsTable}.currency", "{$dealsTable}.status")
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) $row->currency));
            $result[$code] ??= ['open_count' => 0, 'open_value' => 0.0, 'won_count' => 0, 'won_value' => 0.0, 'lost_count' => 0];
            $status = (string) $row->status;

            match ($status) {
                DealStatus::Open->value => $result[$code] = [...$result[$code], 'open_count' => (int) $row->deal_count, 'open_value' => round((float) $row->deal_amount, 2)],
                DealStatus::Won->value => $result[$code] = [...$result[$code], 'won_count' => (int) $row->deal_count, 'won_value' => round((float) $row->deal_amount, 2)],
                DealStatus::Lost->value => $result[$code] = [...$result[$code], 'lost_count' => (int) $row->deal_count],
                default => null,
            };
        }

        ksort($result);

        return $result;
    }
}
