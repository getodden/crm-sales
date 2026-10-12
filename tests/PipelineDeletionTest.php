<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Exceptions\PipelineInUseException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;

class PipelineDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Testbench's SQLite connection leaves foreign keys off; these tests are about what they do.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    public function test_a_stage_with_deals_cannot_be_deleted_and_its_deals_and_quotes_survive(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->first();
        $deal = Deal::factory()->won()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);
        $quote = Quote::factory()->create(['deal_id' => $deal->id]);

        try {
            $stage->delete();
            $this->fail('The stage was deleted.');
        } catch (PipelineInUseException) {
        }

        $this->assertModelExists($stage);
        $this->assertModelExists($deal);
        $this->assertModelExists($quote);
    }

    public function test_a_stage_whose_only_deal_is_trashed_cannot_be_deleted(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->first();
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id])->delete();

        $this->expectException(PipelineInUseException::class);

        $stage->delete();
    }

    public function test_a_stage_a_deal_passed_through_keeps_its_history(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        [$first, $second] = [$pipeline->stages[0], $pipeline->stages[1]];
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $first->id]);
        $deal->moveToStage($second);
        $deal->moveToStage($first);

        $this->assertTrue($second->isInUse());
        $this->expectException(PipelineInUseException::class);

        $second->delete();
    }

    public function test_the_database_refuses_the_delete_too(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->first();
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);

        try {
            DB::table((new PipelineStage)->getTable())->where('id', $stage->id)->delete();
            $this->fail('The stage was deleted.');
        } catch (QueryException) {
        }

        $this->assertSame(1, Deal::query()->where('stage_id', $stage->id)->count());
    }

    public function test_an_unused_stage_can_be_deleted(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->first();

        $this->assertFalse($stage->isInUse());
        $this->assertTrue($stage->delete());
        $this->assertModelMissing($stage);
    }

    public function test_a_pipeline_with_deals_cannot_be_deleted(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

        try {
            $pipeline->delete();
            $this->fail('The pipeline was deleted.');
        } catch (PipelineInUseException) {
        }

        $this->assertModelExists($pipeline);
        $this->assertModelExists($deal);
    }

    public function test_a_pipeline_a_deal_moved_out_of_cannot_be_deleted(): void
    {
        $old = Pipeline::factory()->withStages()->create();
        $new = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $old->id, 'stage_id' => $old->stages[0]->id]);
        $deal->moveToStage($old->stages[1]);
        $deal->moveToStage($new->stages[0]);

        $this->assertSame(1, DealStageHistory::query()->where('to_stage_id', $old->stages[1]->id)->count());
        $this->expectException(PipelineInUseException::class);

        $old->delete();
    }

    public function test_an_unused_pipeline_can_be_deleted_with_its_stages(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        $this->assertFalse($pipeline->isInUse());
        $this->assertTrue($pipeline->delete());
        $this->assertSame(0, PipelineStage::query()->where('pipeline_id', $pipeline->id)->count());
    }
}
