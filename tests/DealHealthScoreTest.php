<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\CalculateDealHealthScoreAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;

class DealHealthScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_threaded_deal_with_recent_activity_scores_high(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
            'expected_close_date' => now()->addDays(30),
        ]);

        $contact1 = Contact::factory()->create();
        $contact2 = Contact::factory()->create();
        $deal->contacts()->attach([$contact1->id, $contact2->id], [
            'parent_type' => $deal->getMorphClass(),
            'child_type' => $contact1->getMorphClass(),
        ]);

        $deal->logActivity(ActivityType::Call, 'Strategy sync with buyer team');

        Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
        ]);

        $action = new CalculateDealHealthScoreAction;
        $health = $action->execute($deal);

        $this->assertGreaterThanOrEqual(75, $health['score']);
        $this->assertSame('strong', $health['status']);
        $this->assertSame('success', $health['badge_color']);
        $this->assertStringContainsString('Strong', $health['badge_label']);
    }

    public function test_stalled_deal_without_activity_scores_at_risk(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->first();
        $stage->update(['rot_after_days' => 5]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => DealStatus::Open,
            'created_at' => now()->subDays(15),
        ]);

        $action = new CalculateDealHealthScoreAction;
        $health = $action->execute($deal);

        $this->assertLessThan(50, $health['score']);
        $this->assertContains('Deal Rotting', array_column($health['factors'], 'name'));
        $this->assertNotEmpty($health['recommendations']);
    }

    public function test_a_close_date_of_today_has_not_expired(): void
    {
        $factors = fn (Deal $deal): array => array_column((new CalculateDealHealthScoreAction)->execute($deal)['factors'], 'name');

        $this->travelTo(now()->setTime(15, 0));
        $today = Deal::factory()->create(['expected_close_date' => today()]);
        $yesterday = Deal::factory()->create(['expected_close_date' => today()->subDay()]);

        $this->assertNotContains('Close Date Expired', $factors($today->fresh()));
        $this->assertContains('Close Date Expired', $factors($yesterday->fresh()));
    }

    /**
     * Deals covering every factor: contacts, activity recency, rot, quotes, close dates and closed statuses.
     *
     * @return list<int>
     */
    private function variedDeals(int $count): array
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages->values();
        $stages[0]->update(['rot_after_days' => 5]);
        $stages[1]->update(['rot_after_days' => null]);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $deal = Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stages[$i % 2]->id,
                'status' => match ($i % 7) {
                    5 => DealStatus::Won,
                    6 => DealStatus::Lost,
                    default => DealStatus::Open,
                },
                'expected_close_date' => match ($i % 3) {
                    0 => null,
                    1 => today()->subDays(2),
                    default => today()->addDays(10),
                },
                'created_at' => now()->subDays(20),
            ]);

            $contacts = Contact::factory()->count($i % 3)->create(['lifecycle_stage' => 'lead']);
            foreach ($contacts as $contact) {
                $deal->contacts()->attach($contact->id, [
                    'parent_type' => $deal->getMorphClass(),
                    'child_type' => $contact->getMorphClass(),
                ]);
            }

            if ($i % 4 !== 0) {
                // Several activities; only the latest counts.
                foreach ([30, [2, 8, 20][$i % 3]] as $daysAgo) {
                    $activity = $deal->logActivity(ActivityType::Call, "Call {$daysAgo}");
                    Activity::query()->whereKey($activity->id)->update(['created_at' => now()->subDays($daysAgo)]);
                }
            }

            if ($i % 2 === 1) {
                foreach ([[QuoteStatus::Draft, 10], [[QuoteStatus::Sent, QuoteStatus::Accepted, QuoteStatus::Draft][$i % 3], 1]] as [$status, $daysAgo]) {
                    $quote = Quote::factory()->create(['deal_id' => $deal->id, 'status' => $status]);
                    Quote::query()->whereKey($quote->id)->update(['created_at' => now()->subDays($daysAgo)]);
                }
            }

            if ($i % 5 !== 0) {
                foreach ([15, [1, 9][$i % 2]] as $daysAgo) {
                    DealStageHistory::query()->create(['deal_id' => $deal->id, 'to_stage_id' => $deal->stage_id, 'entered_at' => now()->subDays($daysAgo)]);
                }
            }

            $ids[] = $deal->id;
        }

        return $ids;
    }

    public function test_execute_many_scores_each_deal_as_execute_does(): void
    {
        $ids = $this->variedDeals(14);
        $action = new CalculateDealHealthScoreAction;

        $expected = [];
        foreach ($ids as $id) {
            $expected[$id] = $action->execute(Deal::query()->findOrFail($id));
        }

        $many = $action->executeMany(Deal::query()->whereKey($ids)->orderBy('id')->get());

        $this->assertSame(array_map('strval', $ids), array_map('strval', array_keys($many)));
        foreach ($ids as $id) {
            $this->assertSame($expected[$id], $many[$id], "Deal {$id} scores differently in a batch");
        }
        // The fixture reaches every outcome.
        $statuses = array_unique(array_column($many, 'status'));
        sort($statuses);
        $this->assertSame(['at_risk', 'moderate', 'stalled', 'strong'], $statuses);
    }

    public function test_execute_many_runs_a_fixed_number_of_queries(): void
    {
        $ids = $this->variedDeals(14);
        $action = new CalculateDealHealthScoreAction;

        $count = function (array $ids) use ($action): int {
            $deals = Deal::query()->whereKey($ids)->get();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $action->executeMany($deals);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count(array_slice($ids, 0, 2));
        $many = $count($ids);

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(5, $many);
        $this->assertSame([], $action->executeMany([]));
    }
}
