<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Odden\Core\Models\Activity;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Quote;

/**
 * @phpstan-type DealHealthScore array{
 *     score: int,
 *     status: 'strong'|'moderate'|'at_risk'|'stalled',
 *     badge_label: string,
 *     badge_color: string,
 *     factors: list<array{name: string, points: int, positive: bool, description: string}>,
 *     recommendations: list<string>
 * }
 */
class CalculateDealHealthScoreAction
{
    /**
     * Calculate an algorithmic health and win-probability score (0-100) for a deal.
     *
     * @return DealHealthScore
     */
    public function execute(Deal $deal): array
    {
        return $this->scoreAll(new EloquentCollection([$deal]))[0];
    }

    /**
     * Score many deals at once (a table page, a Kanban board): what the scores read is loaded in a fixed number of
     * queries whatever the number of deals, and each score is the same as execute() returns for that deal.
     *
     * @param  iterable<Deal>  $deals
     * @return array<int|string, DealHealthScore> keyed by deal id
     */
    public function executeMany(iterable $deals): array
    {
        $collection = new EloquentCollection;
        foreach ($deals as $deal) {
            $collection->push($deal);
        }

        $all = $this->scoreAll($collection);

        $scores = [];
        foreach ($collection->values() as $i => $deal) {
            $scores[$deal->getKey()] = $all[$i];
        }

        return $scores;
    }

    /**
     * @param  EloquentCollection<int, Deal>  $deals
     * @return list<DealHealthScore> in the order of $deals
     */
    private function scoreAll(EloquentCollection $deals): array
    {
        $deals = $deals->values();

        if ($deals->isEmpty()) {
            return [];
        }

        // Closed deals score without reading anything.
        $open = $deals->filter(fn (Deal $deal): bool => $deal->status !== DealStatus::Won && $deal->status !== DealStatus::Lost);
        $saved = $open->filter(fn (Deal $deal): bool => $deal->exists)->values();

        $contactCounts = [];
        $latestActivities = [];
        $latestQuotes = [];
        $latestHistories = [];

        if ($open->isNotEmpty()) {
            $open->loadMissing('stage');
        }

        if ($saved->isNotEmpty()) {
            $template = new Deal;

            $contactCounts = Deal::query()
                ->withoutGlobalScopes()
                ->select($template->getQualifiedKeyName())
                ->whereKey($saved->modelKeys())
                ->withCount('contacts')
                ->toBase()
                ->get()
                ->mapWithKeys(fn (object $row): array => [(string) $row->{$template->getKeyName()} => (int) $row->contacts_count])
                ->all();

            /** @var MorphOne<Activity, Deal> $activity */
            $activity = Relation::noConstraints(fn (): MorphOne => $template->morphOne(Activity::class, 'subject')->latestOfMany('created_at', 'latest_activity'));
            $activity->addEagerConstraints($saved->all());
            $latestActivities = $activity->getEager()->keyBy(fn (Activity $model): string => (string) $model->subject_id)->all();

            /** @var HasOne<Quote, Deal> $quote */
            $quote = Relation::noConstraints(fn (): HasOne => $template->hasOne(Quote::class, 'deal_id')->latestOfMany('created_at', 'latest_quote'));
            $quote->addEagerConstraints($saved->all());
            $latestQuotes = $quote->getEager()->keyBy(fn (Quote $model): string => (string) $model->deal_id)->all();

            /** @var HasOne<DealStageHistory, Deal> $history */
            $history = Relation::noConstraints(fn (): HasOne => $template->hasOne(DealStageHistory::class, 'deal_id')->latestOfMany('entered_at', 'latest_stage_entry'));
            $history->addEagerConstraints($saved->all());
            $latestHistories = $history->getEager()->keyBy(fn (DealStageHistory $model): string => (string) $model->deal_id)->all();
        }

        $scores = [];
        foreach ($deals as $deal) {
            if ($deal->status === DealStatus::Won || $deal->status === DealStatus::Lost) {
                $scores[] = $this->closedScore($deal->status);

                continue;
            }

            $key = (string) $deal->getKey();

            $scores[] = $this->score(
                $deal,
                $contactCounts[$key] ?? 0,
                $latestActivities[$key] ?? null,
                $latestQuotes[$key] ?? null,
                $latestHistories[$key] ?? null,
            );
        }

        return $scores;
    }

    /**
     * @return DealHealthScore
     */
    private function closedScore(DealStatus $status): array
    {
        if ($status === DealStatus::Won) {
            return [
                'score' => 100,
                'status' => 'strong',
                'badge_label' => 'Closed Won (100)',
                'badge_color' => 'success',
                'factors' => [
                    ['name' => 'Deal Won', 'points' => 100, 'positive' => true, 'description' => 'Deal is successfully closed.'],
                ],
                'recommendations' => [],
            ];
        }

        return [
            'score' => 0,
            'status' => 'stalled',
            'badge_label' => 'Closed Lost (0)',
            'badge_color' => 'danger',
            'factors' => [
                ['name' => 'Deal Lost', 'points' => 0, 'positive' => false, 'description' => 'Deal was marked lost.'],
            ],
            'recommendations' => ['Review lost retro notes and schedule a re-engagement touch in 90 days.'],
        ];
    }

    /**
     * @return DealHealthScore
     */
    private function score(Deal $deal, int $contactCount, ?Activity $latestActivity, ?Quote $activeQuote, ?DealStageHistory $latestHistory): array
    {
        $score = 50; // Neutral baseline
        $factors = [];
        $recommendations = [];

        // 1. Multi-threading Factor (Deals with 2+ contacts close 2.5x more often)
        if ($contactCount >= 2) {
            $score += 20;
            $factors[] = [
                'name' => 'Multi-threaded (2+ Contacts)',
                'points' => 20,
                'positive' => true,
                'description' => "Associated with {$contactCount} key stakeholders.",
            ];
        } elseif ($contactCount === 1) {
            $score += 10;
            $factors[] = [
                'name' => 'Single Contact Attached',
                'points' => 10,
                'positive' => true,
                'description' => 'Deal has a primary contact.',
            ];
            $recommendations[] = 'Multi-thread this deal: add a secondary decision-maker or champion (+10 pts).';
        } else {
            $score -= 15;
            $factors[] = [
                'name' => 'No Contacts Associated',
                'points' => -15,
                'positive' => false,
                'description' => 'Deal lacks an associated contact.',
            ];
            $recommendations[] = 'Associate a contact to establish a direct buyer champion.';
        }

        // 2. Activity Recency & Engagement
        if ($latestActivity !== null && $latestActivity->created_at !== null) {
            $daysSinceActivity = (int) $latestActivity->created_at->diffInDays(now());
            if ($daysSinceActivity <= 5) {
                $score += 20;
                $factors[] = [
                    'name' => 'Active Engagement',
                    'points' => 20,
                    'positive' => true,
                    'description' => "Last touch {$daysSinceActivity}d ago ({$latestActivity->type->label()}).",
                ];
            } elseif ($daysSinceActivity <= 12) {
                $score += 5;
                $factors[] = [
                    'name' => 'Recent Touch',
                    'points' => 5,
                    'positive' => true,
                    'description' => "Last touch {$daysSinceActivity}d ago.",
                ];
                $recommendations[] = 'Schedule a check-in call to maintain deal momentum.';
            } else {
                $score -= 25;
                $factors[] = [
                    'name' => 'Low Activity Recency',
                    'points' => -25,
                    'positive' => false,
                    'description' => "No communication logged in the last {$daysSinceActivity} days.",
                ];
                $recommendations[] = "Outreach overdue: {$daysSinceActivity} days without activity. Log a call or email immediately.";
            }
        } else {
            $score -= 10;
            $factors[] = [
                'name' => 'No Activities Logged',
                'points' => -10,
                'positive' => false,
                'description' => 'No activity history logged yet on this deal.',
            ];
            $recommendations[] = 'Log initial discovery call or meeting notes.';
        }

        // 3. Stage Velocity & Rotting Status
        // Same rule as Deal::isRotten() and Deal::daysInCurrentStage(), on the preloaded latest stage entry.
        $enteredAt = $latestHistory !== null ? $latestHistory->entered_at : ($deal->created_at ?? now());
        $daysInStage = (int) $enteredAt->diffInDays(now());
        $rotAfterDays = $deal->stage->rot_after_days;

        if ($rotAfterDays !== null && $rotAfterDays > 0 && $daysInStage >= $rotAfterDays) {
            $score -= 30;
            $rotDays = $rotAfterDays;
            $factors[] = [
                'name' => 'Deal Rotting',
                'points' => -30,
                'positive' => false,
                'description' => "Stalled in {$deal->stage->name} for {$daysInStage}d (threshold {$rotDays}d).",
            ];
            $recommendations[] = 'Advance deal to next stage or conduct an alignment call to unblock progress.';
        } else {
            $score += 10;
            $factors[] = [
                'name' => 'Stage Velocity Normal',
                'points' => 10,
                'positive' => true,
                'description' => "Moving within expected pace for {$deal->stage->name}.",
            ];
        }

        // 4. Quote Momentum
        if ($activeQuote !== null) {
            if ($activeQuote->status === QuoteStatus::Accepted) {
                $score += 30;
                $factors[] = [
                    'name' => 'Quote Accepted',
                    'points' => 30,
                    'positive' => true,
                    'description' => "Proposal #{$activeQuote->quote_number} is accepted.",
                ];
            } elseif ($activeQuote->status === QuoteStatus::Sent) {
                $score += 15;
                $factors[] = [
                    'name' => 'Quote Sent',
                    'points' => 15,
                    'positive' => true,
                    'description' => "Proposal #{$activeQuote->quote_number} delivered to buyer.",
                ];
            }
        }

        // 5. Expected Close Date
        if ($deal->expected_close_date !== null) {
            // A date cast is midnight, so isPast() would already call today's close date expired.
            if ($deal->expected_close_date->lt(today())) {
                $score -= 15;
                $factors[] = [
                    'name' => 'Close Date Expired',
                    'points' => -15,
                    'positive' => false,
                    'description' => 'Target close date was in the past.',
                ];
                $recommendations[] = 'Update expected close date to align with the real buyer decision timeline.';
            } else {
                $score += 5;
                $factors[] = [
                    'name' => 'Target Close Defined',
                    'points' => 5,
                    'positive' => true,
                    'description' => "Target close set for {$deal->expected_close_date->format('M j, Y')}.",
                ];
            }
        }

        // Clamp between 5 and 99 for open deals
        $finalScore = max(5, min(99, $score));

        $status = match (true) {
            $finalScore >= 75 => 'strong',
            $finalScore >= 50 => 'moderate',
            $finalScore >= 30 => 'at_risk',
            default => 'stalled',
        };

        $badgeColor = match ($status) {
            'strong' => 'success',
            'moderate' => 'info',
            'at_risk' => 'warning',
            'stalled' => 'danger',
        };

        $badgeLabel = match ($status) {
            'strong' => "🔥 Strong ({$finalScore})",
            'moderate' => "⚡ Good ({$finalScore})",
            'at_risk' => "⚠️ At Risk ({$finalScore})",
            'stalled' => "❄️ Stalled ({$finalScore})",
        };

        return [
            'score' => $finalScore,
            'status' => $status,
            'badge_label' => $badgeLabel,
            'badge_color' => $badgeColor,
            'factors' => $factors,
            'recommendations' => $recommendations,
        ];
    }
}
