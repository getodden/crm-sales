<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Actions\CalculateStageVelocityAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class StageVelocityTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_stage_velocity_and_stale_deal_counts(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages()->get();
        $leadStage = $stages[0];
        $leadStage->update(['rot_after_days' => 5]);

        // Deal 1: in lead stage for 10 days (stale)
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $leadStage->id,
            'created_at' => now()->subDays(10),
        ]);

        // Deal 2: in lead stage for 2 days (not stale)
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $leadStage->id,
            'created_at' => now()->subDays(2),
        ]);

        $action = new CalculateStageVelocityAction;
        $metrics = $action->execute($pipeline->id);

        $this->assertArrayHasKey('stages', $metrics);
        $this->assertArrayHasKey('average_sales_cycle_days', $metrics);
        $this->assertSame(1, $metrics['stages'][$leadStage->id]['stale_deal_count']);
    }

    public function test_stage_velocity_can_be_scoped_to_a_team(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $leadStage = $pipeline->stages()->get()[0];
        $leadStage->update(['rot_after_days' => 5]);

        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $leadStage->id, 'created_at' => now()->subDays(10), 'team_id' => 1]);
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $leadStage->id, 'created_at' => now()->subDays(10), 'team_id' => 2]);
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $leadStage->id, 'created_at' => now()->subDays(10), 'team_id' => 2]);

        $action = new CalculateStageVelocityAction;

        $this->assertSame(3, $action->execute($pipeline->id)['stages'][$leadStage->id]['stale_deal_count']);
        $this->assertSame(1, $action->execute($pipeline->id, 1)['stages'][$leadStage->id]['stale_deal_count']);
        $this->assertSame(2, $action->execute($pipeline->id, 2)['stages'][$leadStage->id]['stale_deal_count']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_deals(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages()->get();
        $stages[0]->update(['rot_after_days' => 5]);
        $stages[1]->update(['rot_after_days' => 5]);

        $queries = function (int $deals) use ($pipeline, $stages): int {
            foreach (range(1, $deals) as $i) {
                $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stages[0]->id]);
                $deal->moveToStage($stages[1]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            (new CalculateStageVelocityAction)->execute($pipeline->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($queries(1), $queries(5));
    }
}
