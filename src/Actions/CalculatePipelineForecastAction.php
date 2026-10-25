<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\TooManyToFilter;
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
     * @param  Builder<covariant Model>|null  $visibleDeals
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
     *     average_won_deal_size: float,
     *     lost_reasons: array<string, int>,
     *     stale_deals_count: int
     * }
     */
    public function execute(?int $pipelineId = null, ?int $teamId = null, ?Builder $visibleDeals = null, ?string $currency = null): array
    {
        $dealsTable = config('odden-sales.tables.deals', 'odden_deals');
        $stagesTable = config('odden-sales.tables.stages', 'odden_pipeline_stages');
        $amounts = app(EncryptedAmounts::class);

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

        $otherCurrencies = $this->otherCurrencies(clone $baseQuery, $currency, $dealsTable, $amounts);
        $baseQuery->where("{$dealsTable}.currency", $currency);

        // 1. Open deals and value
        $openQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Open->value);
        $openCount = $openQuery->count();
        $openValue = $amounts->sum($openQuery, 'amount');

        // 2. Weighted forecast value based on stage win probability, summed in the database (in PHP for the amounts a workspace keeps encrypted)
        $weightedForecast = $amounts->weighted(
            (clone $openQuery)->join($stagesTable, "{$stagesTable}.id", '=', "{$dealsTable}.stage_id"),
            'amount',
            "{$stagesTable}.probability",
        ) / 100.0;

        // 3. Won deals
        $wonQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Won->value);
        $wonCount = $wonQuery->count();
        $wonValue = $amounts->sum($wonQuery, 'amount');

        // 4. Lost deals & Win rate
        $lostQuery = (clone $baseQuery)->where("{$dealsTable}.status", DealStatus::Lost->value);
        $lostCount = $lostQuery->count();

        $closedCount = $wonCount + $lostCount;
        $winRate = $closedCount > 0 ? round(($wonCount / $closedCount) * 100, 1) : 0.0;

        // 5. Average deal size. `average_deal_size` keeps its original meaning (every deal in the
        // reporting currency: open, won and lost) for compatibility; `average_won_deal_size` is the
        // average of won deals only, which is what "average deal size" usually means in sales reporting.
        $averageDealSize = $amounts->average($baseQuery, 'amount');
        $averageWonDealSize = $wonCount > 0 ? $wonValue / $wonCount : 0.0;

        // 6. Lost reasons breakdown, most frequent first. The reasons are stored encrypted, so the lost deals are read and counted here.
        $lostReasons = [];

        try {
            $reasons = app(DecryptedFilter::class)->tally(clone $lostQuery, 'lost_reason');
        } catch (TooManyToFilter) {
            // Too many lost deals to decrypt for a breakdown: the counts above are still right.
            $reasons = [];
        }

        foreach ($reasons as $reason => $count) {
            $reason = (string) $reason !== '' ? (string) $reason : 'unspecified';
            $lostReasons[$reason] = ($lostReasons[$reason] ?? 0) + $count;
        }
        uksort($lostReasons, fn (string $a, string $b): int => [$lostReasons[$b], $a] <=> [$lostReasons[$a], $b]);

        // 7. Stale deals count: only open deals in stages that go stale can be stale, so only those are loaded
        $staleCount = (clone $openQuery)
            ->whereHas('stage', fn ($stage) => $stage->where('rot_after_days', '>', 0))
            ->with(['stage', 'stageHistory'])
            ->get()
            ->filter(fn (Deal $deal): bool => $deal->isRotten())
            ->count();

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
            'average_won_deal_size' => round($averageWonDealSize, 2),
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
    protected function otherCurrencies(Builder $query, string $currency, string $dealsTable, EncryptedAmounts $amounts): array
    {
        $rows = $amounts->grouped(
            $query->where("{$dealsTable}.currency", '!=', $currency),
            'amount',
            ["{$dealsTable}.currency", "{$dealsTable}.status"],
        );

        $result = [];

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) $row->currency));
            $result[$code] ??= ['open_count' => 0, 'open_value' => 0.0, 'won_count' => 0, 'won_value' => 0.0, 'lost_count' => 0];
            $status = (string) $row->status;

            match ($status) {
                DealStatus::Open->value => $result[$code] = [...$result[$code], 'open_count' => (int) $row->records, 'open_value' => round((float) $row->total, 2)],
                DealStatus::Won->value => $result[$code] = [...$result[$code], 'won_count' => (int) $row->records, 'won_value' => round((float) $row->total, 2)],
                DealStatus::Lost->value => $result[$code] = [...$result[$code], 'lost_count' => (int) $row->records],
                default => null,
            };
        }

        ksort($result);

        return $result;
    }
}
