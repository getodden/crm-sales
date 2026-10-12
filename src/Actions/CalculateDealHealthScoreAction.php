<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Models\Activity;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;

class CalculateDealHealthScoreAction
{
    /**
     * Calculate an algorithmic health and win-probability score (0-100) for a deal.
     *
     * @return array{
     *     score: int,
     *     status: 'strong'|'moderate'|'at_risk'|'stalled',
     *     badge_label: string,
     *     badge_color: string,
     *     factors: list<array{name: string, points: int, positive: bool, description: string}>,
     *     recommendations: list<string>
     * }
     */
    public function execute(Deal $deal): array
    {
        if ($deal->status === DealStatus::Won) {
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

        if ($deal->status === DealStatus::Lost) {
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

        $score = 50; // Neutral baseline
        $factors = [];
        $recommendations = [];

        // 1. Multi-threading Factor (Deals with 2+ contacts close 2.5x more often)
        $contactCount = $deal->contacts()->count();
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
        /** @var Activity|null $latestActivity */
        $latestActivity = $deal->activities()->latest('created_at')->first();
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
        if ($deal->isRotten()) {
            $score -= 30;
            $rotDays = $deal->stage->rot_after_days ?? 14;
            $daysInStage = $deal->daysInCurrentStage();
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
        /** @var Quote|null $activeQuote */
        $activeQuote = $deal->quotes()->latest()->first();
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
