<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\CalculateDealHealthScoreAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
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
}
