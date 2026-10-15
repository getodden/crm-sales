<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

/**
 * The factory's stage codes never collide within a pipeline, whatever names the test gives or Faker draws
 * ((pipeline_id, code) is unique), and a code follows the name that was given.
 */
class PipelineStageFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_stages_in_one_pipeline_never_collide(): void
    {
        $pipeline = Pipeline::factory()->create();

        PipelineStage::factory()->count(60)->create(['pipeline_id' => $pipeline->id]);

        $codes = $pipeline->stages()->pluck('code');
        $this->assertCount(60, $codes);
        $this->assertCount(60, $codes->unique());
    }

    public function test_the_same_name_twice_gets_a_distinct_code(): void
    {
        $pipeline = Pipeline::factory()->create();

        $first = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'name' => 'Qualified']);
        $second = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'name' => 'Qualified']);

        $this->assertSame('qualified', $first->code);
        $this->assertSame('qualified_2', $second->code);
    }

    public function test_the_code_follows_a_given_name(): void
    {
        $stage = PipelineStage::factory()->create(['name' => 'Proposal Sent']);

        $this->assertSame('proposal_sent', $stage->code);
    }

    public function test_an_explicit_code_is_kept(): void
    {
        $stage = PipelineStage::factory()->create(['code' => 'custom_code']);

        $this->assertSame('custom_code', $stage->code);
    }

    public function test_the_same_code_in_different_pipelines_is_fine(): void
    {
        $a = PipelineStage::factory()->create(['name' => 'Qualified']);
        $b = PipelineStage::factory()->create(['name' => 'Qualified']);

        $this->assertSame('qualified', $a->code);
        $this->assertSame('qualified', $b->code);
    }

    public function test_the_closed_states_keep_their_fixed_codes(): void
    {
        $this->assertSame('closed_won', PipelineStage::factory()->closedWon()->create()->code);
        $this->assertSame('closed_lost', PipelineStage::factory()->closedLost()->create()->code);
    }

    public function test_a_made_stage_has_a_code_without_touching_the_database(): void
    {
        $stage = PipelineStage::factory()->make(['name' => 'Negotiation']);

        $this->assertSame('negotiation', $stage->code);
    }
}
