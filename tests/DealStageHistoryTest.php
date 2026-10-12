<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;

class DealStageHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_in_stage_is_recorded_as_positive_whole_seconds(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        [$first, $second, $third] = [$pipeline->stages[0], $pipeline->stages[1], $pipeline->stages[2]];
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $first->id]);

        $this->travelTo(now()->startOfMinute());
        $deal->moveToStage($second);

        $this->travel(90)->minutes();
        $deal->moveToStage($third);

        $closed = DealStageHistory::query()->where('deal_id', $deal->id)->where('to_stage_id', $second->id)->firstOrFail();

        $this->assertSame(5400, $closed->duration_in_stage_seconds);
    }

    public function test_moving_deal_between_stages_tracks_duration_and_history(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages;
        $discoveryStage = $stages[0];
        $qualificationStage = $stages[1];
        $proposalStage = $stages[2];

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $discoveryStage->id,
        ]);

        // Move to qualification
        $deal->moveToStage($qualificationStage);

        $this->assertDatabaseHas('odden_deal_stage_history', [
            'deal_id' => $deal->id,
            'from_stage_id' => $discoveryStage->id,
            'to_stage_id' => $qualificationStage->id,
        ]);

        // Move to proposal
        $deal->moveToStage($proposalStage);

        $historyRecords = DealStageHistory::query()->where('deal_id', $deal->id)->orderBy('id')->get();

        // The deal was created without a history row, so its stay in the first stage is recorded on the first move.
        $this->assertCount(3, $historyRecords);
        $this->assertSame($discoveryStage->id, $historyRecords[0]->to_stage_id);
        $this->assertNull($historyRecords[0]->from_stage_id);
        $this->assertNotNull($historyRecords[0]->exited_at);

        $this->assertSame($qualificationStage->id, $historyRecords[1]->to_stage_id);
        $this->assertNotNull($historyRecords[1]->exited_at);
        $this->assertNotNull($historyRecords[1]->duration_in_stage_seconds);

        $this->assertSame($qualificationStage->id, $historyRecords[2]->from_stage_id);
        $this->assertSame($proposalStage->id, $historyRecords[2]->to_stage_id);
        $this->assertNull($historyRecords[2]->exited_at);
    }

    public function test_a_deal_created_without_history_records_its_first_stage_from_its_creation(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        [$first, $second] = [$pipeline->stages[0], $pipeline->stages[1]];
        $this->travelTo(now()->startOfMinute());
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $first->id]);

        $this->travel(3)->days();
        $deal->moveToStage($second);

        $first = DealStageHistory::query()->where('deal_id', $deal->id)->where('to_stage_id', $first->id)->sole();
        $this->assertSame(3 * 86400, $first->duration_in_stage_seconds);
    }

    public function test_the_first_stage_stay_is_never_negative(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[0]->id, 'created_at' => now()->addSeconds(30)]);

        $deal->moveToStage($pipeline->stages[1]);

        $first = DealStageHistory::query()->where('deal_id', $deal->id)->where('to_stage_id', $pipeline->stages[0]->id)->sole();
        $this->assertSame(0, $first->duration_in_stage_seconds);
    }

    public function test_moving_the_same_deal_object_back_and_forth_reads_the_current_stage(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        [$a, $b, $c] = [$pipeline->stages[0], $pipeline->stages[1], $pipeline->stages[2]];
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $a->id]);
        $deal->load('stage');

        $deal->moveToStage($b);
        $this->assertSame($b->id, $deal->stage->id);

        $deal->moveToStage($a);
        $this->assertSame($a->id, $deal->fresh()?->stage_id, 'Moving back to the first stage is not a no-op.');

        $deal->moveToStage($b);
        $deal->moveToStage($c);

        $last = DealStageHistory::query()->where('deal_id', $deal->id)->latest('id')->firstOrFail();
        $this->assertSame($b->id, $last->from_stage_id);
        $this->assertSame($c->id, $last->to_stage_id);
    }

    public function test_a_stale_copy_of_the_deal_moves_from_where_the_deal_really_is(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        [$a, $b, $c] = [$pipeline->stages[0], $pipeline->stages[1], $pipeline->stages[2]];
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $a->id]);
        $stale = Deal::query()->with('stage')->findOrFail($deal->id);

        $deal->moveToStage($b);
        $stale->moveToStage($a);

        $this->assertSame($a->id, $deal->fresh()?->stage_id);
        $this->assertSame(1, DealStageHistory::query()->where('deal_id', $deal->id)->whereNull('exited_at')->count());

        $stale->moveToStage($c);
        $last = DealStageHistory::query()->where('deal_id', $deal->id)->latest('id')->firstOrFail();
        $this->assertSame($a->id, $last->from_stage_id);
    }

    public function test_a_stage_move_locks_the_deal_row(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks.');
        }

        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[0]->id]);
        DB::enableQueryLog();

        $deal->moveToStage($pipeline->stages[1]);

        $dealLocks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'odden_deals')
            && str_contains(strtolower($query['query']), 'for update'));
        $this->assertNotEmpty($dealLocks);
    }
}
