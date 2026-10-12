<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Enums\QuotaPeriod;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Tests\Fixtures\User;

class LeadRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_route_leads_using_round_robin_strategy(): void
    {
        $rep1 = User::factory()->create(['name' => 'Alice']);
        $rep2 = User::factory()->create(['name' => 'Bob']);

        LeadRoutingRule::query()->create([
            'name' => 'Inbound Round Robin',
            'strategy' => LeadRoutingStrategy::RoundRobin,
            'assigned_user_ids' => [$rep1->id, $rep2->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $contact1 = Contact::factory()->create(['lead_status' => LeadStatus::New]);
        $contact2 = Contact::factory()->create(['lead_status' => LeadStatus::New]);
        $contact3 = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        $action = new RouteLeadAction;

        // First lead goes to rep1 (index 0)
        $result1 = $action->execute($contact1);
        $this->assertNotNull($result1);
        $this->assertSame($rep1->id, $result1['assigned_user_id']);
        $this->assertSame($rep1->id, $contact1->fresh()->owner_id);

        // Second lead goes to rep2 (index 1)
        $result2 = $action->execute($contact2);
        $this->assertNotNull($result2);
        $this->assertSame($rep2->id, $result2['assigned_user_id']);
        $this->assertSame($rep2->id, $contact2->fresh()->owner_id);

        // Third lead wraps around to rep1
        $result3 = $action->execute($contact3);
        $this->assertNotNull($result3);
        $this->assertSame($rep1->id, $result3['assigned_user_id']);

        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $contact1->getMorphClass(),
            'subject_id' => $contact1->id,
            'type' => ActivityType::Note->value,
            'title' => 'Lead Routed to Alice',
        ]);
    }

    public function test_territory_strategy_routes_matching_leads_to_the_territory_owner(): void
    {
        $owner = User::factory()->create(['name' => 'Territory Owner']);
        $other = User::factory()->create(['name' => 'Other Rep']);

        LeadRoutingRule::query()->create([
            'name' => 'EMEA Territory',
            'strategy' => LeadRoutingStrategy::Territory,
            'criteria' => ['timezone' => 'Europe/Berlin'],
            'assigned_user_ids' => [$owner->id, $other->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $first = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'Europe/Berlin']);
        $second = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'Europe/Berlin']);
        $outside = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'America/New_York']);

        $action = new RouteLeadAction;

        $this->assertSame($owner->id, $action->execute($first)['assigned_user_id']);
        $this->assertSame($owner->id, $action->execute($second)['assigned_user_id']);
        $this->assertNull($action->execute($outside));
    }

    public function test_territory_rule_without_criteria_never_applies(): void
    {
        $owner = User::factory()->create();

        LeadRoutingRule::query()->create([
            'name' => 'Misconfigured Territory',
            'strategy' => LeadRoutingStrategy::Territory,
            'assigned_user_ids' => [$owner->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        $this->assertNull((new RouteLeadAction)->execute($contact));
    }

    private function quotaFor(User $rep): SalesQuota
    {
        return SalesQuota::create([
            'user_id' => $rep->id,
            'period_type' => QuotaPeriod::Quarterly,
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
            'target_amount' => 100000.00,
        ]);
    }

    private function quotaRule(User ...$reps): LeadRoutingRule
    {
        return LeadRoutingRule::query()->create([
            'name' => 'Quota balancing',
            'strategy' => LeadRoutingStrategy::QuotaWeighted,
            'assigned_user_ids' => array_map(fn (User $rep): int => $rep->id, $reps),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * @return list<int>
     */
    private function routeLeads(int $count): array
    {
        return array_map(
            fn (): int => (int) (new RouteLeadAction)->execute(Contact::factory()->create())['assigned_user_id'],
            range(1, $count)
        );
    }

    public function test_quota_weighted_routing_leaves_out_reps_without_a_quota(): void
    {
        $manager = User::factory()->create();
        $rep = User::factory()->create();
        $this->quotaFor($rep);
        Deal::factory()->won()->create(['owner_id' => $rep->id, 'amount' => 10000.00]);
        $this->quotaRule($manager, $rep);

        $this->assertSame([$rep->id, $rep->id], $this->routeLeads(2));
    }

    public function test_quota_weighted_routing_takes_turns_between_reps_on_the_same_attainment(): void
    {
        [$a, $b, $c] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $this->quotaFor($a);
        $this->quotaFor($b);
        $this->quotaFor($c);
        $this->quotaRule($a, $b, $c);

        $this->assertSame([$a->id, $b->id, $c->id, $a->id], $this->routeLeads(4));
    }

    public function test_quota_weighted_routing_without_any_quota_takes_turns_over_the_pool(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $this->quotaRule($a, $b);

        $this->assertSame([$a->id, $b->id, $a->id], $this->routeLeads(3));
    }

    public function test_a_criterion_the_lead_type_does_not_have_never_matches(): void
    {
        $owner = User::factory()->create();
        LeadRoutingRule::query()->create([
            'name' => 'Big deals',
            'strategy' => LeadRoutingStrategy::Territory,
            'criteria' => ['min_amount' => 50000],
            'assigned_user_ids' => [$owner->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->assertNull((new RouteLeadAction)->execute(Contact::factory()->create()));
        $this->assertSame($owner->id, (new RouteLeadAction)->execute(Deal::factory()->create(['amount' => 60000]))['assigned_user_id'] ?? null);
    }

    public function test_an_unknown_criterion_cannot_be_saved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeadRoutingRule::query()->create([
            'name' => 'Berlin',
            'strategy' => LeadRoutingStrategy::Territory,
            'criteria' => ['state' => 'CA'],
            'assigned_user_ids' => [User::factory()->create()->id],
            'is_active' => true,
        ]);
    }

    public function test_a_stored_unknown_criterion_matches_nothing(): void
    {
        $owner = User::factory()->create();
        $rule = LeadRoutingRule::query()->create([
            'name' => 'Legacy',
            'strategy' => LeadRoutingStrategy::Territory,
            'criteria' => ['timezone' => 'Europe/Berlin'],
            'assigned_user_ids' => [$owner->id],
            'is_active' => true,
        ]);
        DB::table($rule->getTable())->where('id', $rule->id)->update(['criteria' => json_encode(['industry' => 'Software'])]);

        $this->assertNull((new RouteLeadAction)->execute(Contact::factory()->create()));
    }
}
