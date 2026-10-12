<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\SalesQuota;

class RouteLeadAction
{
    /**
     * Route a Contact or Deal to a sales representative based on active rules.
     *
     * @return array{assigned_user_id: int, rule: LeadRoutingRule}|null
     */
    public function execute(Contact|Deal $target): ?array
    {
        /** @var Collection<int, LeadRoutingRule> $rules */
        $rules = LeadRoutingRule::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            $pool = $rule->assigned_user_ids;
            if (empty($pool)) {
                continue;
            }

            // A territory rule without criteria would match every lead, so it never applies.
            if ($rule->strategy === LeadRoutingStrategy::Territory && empty($rule->criteria)) {
                continue;
            }

            // Check criteria match
            if ($rule->criteria !== null && ! empty($rule->criteria)) {
                if (! $this->matchesCriteria($target, $rule->criteria)) {
                    continue;
                }
            }

            $selectedUserId = $this->selectUser($rule, $pool);

            if ($selectedUserId !== null) {
                $target->update(['owner_id' => $selectedUserId]);

                /** @var object{name: string}|null $user */
                $user = UserModel::query()->find($selectedUserId);
                $userName = $user !== null ? $user->name : "User #{$selectedUserId}";

                $target->logActivity(
                    type: ActivityType::Note,
                    title: "Lead Routed to {$userName}",
                    body: "Assigned via rule [{$rule->name}] using {$rule->strategy->label()}."
                );

                return [
                    'assigned_user_id' => $selectedUserId,
                    'rule' => $rule,
                ];
            }
        }

        return null;
    }

    /**
     * Select next user from the pool according to the rule strategy.
     *
     * @param  list<int>  $pool
     */
    protected function selectUser(LeadRoutingRule $rule, array $pool): ?int
    {
        $count = count($pool);
        if ($count === 0) {
            return null;
        }

        if ($rule->strategy === LeadRoutingStrategy::QuotaWeighted) {
            return $this->selectQuotaWeightedUser($rule, $pool);
        }

        if ($rule->strategy === LeadRoutingStrategy::Territory) {
            // The first user in the pool owns the territory; there is no rotation.
            return (int) $pool[0];
        }

        // Read and advance the pointer under a row lock: two leads routed at the same moment must not both read
        // the same index and go to the same person.
        return DB::transaction(function () use ($rule, $pool, $count): int {
            $locked = LeadRoutingRule::query()->whereKey($rule->getKey())->lockForUpdate()->first() ?? $rule;

            $nextIndex = ($locked->last_assigned_index + 1) % $count;
            $selectedUserId = $pool[$nextIndex] ?? $pool[0];

            $locked->update(['last_assigned_index' => $nextIndex]);
            $rule->last_assigned_index = $nextIndex;

            return (int) $selectedUserId;
        });
    }

    /**
     * Select the rep with the lowest quota attainment in the current period.
     *
     * Reps without a current quota are left out: counting them as 0% would send them every lead. Reps on the same
     * attainment (everyone on the first day of a period, say) take turns, as in round robin. When nobody in the pool
     * has a quota there is nothing to weigh, so the whole pool takes turns.
     *
     * @param  list<int>  $pool
     */
    protected function selectQuotaWeightedUser(LeadRoutingRule $rule, array $pool): int
    {
        $pool = array_values($pool);
        $now = now();
        $attainment = [];

        foreach ($pool as $index => $userId) {
            /** @var SalesQuota|null $quota */
            $quota = SalesQuota::query()
                ->where('user_id', $userId)
                ->where('period_start', '<=', $now)
                ->where('period_end', '>=', $now)
                ->latest('id')
                ->first();

            if ($quota !== null) {
                $attainment[$index] = round((float) app(CalculateQuotaAttainmentAction::class)->execute($quota)['attainment_percent'], 2);
            }
        }

        $eligible = $attainment === [] ? array_keys($pool) : array_keys($attainment, min($attainment), true);

        // The turn pointer is read and advanced under a row lock, as in round robin.
        return DB::transaction(function () use ($rule, $pool, $eligible): int {
            $locked = LeadRoutingRule::query()->whereKey($rule->getKey())->lockForUpdate()->first() ?? $rule;

            $after = array_values(array_filter($eligible, fn (int $index): bool => $index > $locked->last_assigned_index));
            $chosen = $after[0] ?? $eligible[0];

            $locked->update(['last_assigned_index' => $chosen]);
            $rule->last_assigned_index = $chosen;

            return (int) $pool[$chosen];
        });
    }

    /**
     * Determine if target matches rule criteria.
     *
     * @param  array<string, mixed>  $criteria
     */
    protected function matchesCriteria(Contact|Deal $target, array $criteria): bool
    {
        $supported = $target instanceof Contact ? LeadRoutingRule::CONTACT_CRITERIA : LeadRoutingRule::DEAL_CRITERIA;

        foreach ($criteria as $key => $expected) {
            // A criterion this kind of lead doesn't have can't be met: skipping it would match every lead.
            if (! in_array($key, $supported, true)) {
                return false;
            }

            if ($target instanceof Contact) {
                if ($key === 'lead_status' && $target->lead_status->value !== $expected) {
                    return false;
                }
                if ($key === 'timezone' && $target->timezone !== $expected) {
                    return false;
                }
                if ($key === 'city' && $target->companies->first()?->getProperty('city') !== $expected) {
                    return false;
                }
            } else {
                if ($key === 'pipeline_id' && (int) $target->pipeline_id !== (int) $expected) {
                    return false;
                }
                if ($key === 'min_amount' && (float) $target->amount < (float) $expected) {
                    return false;
                }
            }
        }

        return true;
    }
}
