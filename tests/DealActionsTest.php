<?php

declare(strict_types=1);

use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

function dealIn(Pipeline $pipeline, int $stage = 0): Deal
{
    return Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[$stage]->id, 'name' => 'Acme renewal']);
}

it('registers the deal actions with core', function (): void {
    $names = array_keys(app(ActionRegistry::class)->all());

    expect($names)->toContain('deals.search', 'deals.move_stage');
});

it('finds a deal with the stages it can move to', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = dealIn($pipeline);

    $found = app(ActionExecutor::class)->run('deals.search', Actor::ai(1), ['query' => 'acme'])->data['deals'];

    expect($found)->toHaveCount(1)
        ->and($found[0]['id'])->toBe($deal->id)
        ->and($found[0]['stage']['id'])->toBe($pipeline->stages[0]->id)
        ->and(array_column($found[0]['pipeline_stages'], 'id'))->toBe($pipeline->stages->pluck('id')->all());
});

it('moves a deal and records history through the existing action', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = dealIn($pipeline);

    $result = app(ActionExecutor::class)->run('deals.move_stage', Actor::ai(5), ['deal_id' => $deal->id, 'stage_id' => $pipeline->stages[1]->id], via: 'assistant');

    expect($result->ok())->toBeTrue()
        ->and($deal->refresh()->stage_id)->toBe($pipeline->stages[1]->id);

    $this->assertDatabaseHas('odden_deal_stage_history', ['deal_id' => $deal->id, 'to_stage_id' => $pipeline->stages[1]->id, 'user_id' => 5]);
});

it('does nothing when the deal is already in that stage', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = dealIn($pipeline);

    app(ActionExecutor::class)->run('deals.move_stage', Actor::user(1), ['deal_id' => $deal->id, 'stage_id' => $pipeline->stages[0]->id]);

    $this->assertDatabaseCount('odden_deal_stage_history', 0);
});

it('refuses a stage from another pipeline and a deal that does not exist', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $other = Pipeline::factory()->withStages()->create();
    $deal = dealIn($pipeline);

    $executor = app(ActionExecutor::class);
    $wrongStage = $executor->run('deals.move_stage', Actor::user(1), ['deal_id' => $deal->id, 'stage_id' => $other->stages[0]->id]);
    $noDeal = $executor->run('deals.move_stage', Actor::user(1), ['deal_id' => 9999, 'stage_id' => $pipeline->stages[0]->id]);

    expect($wrongStage->status)->toBe(ActionResult::FAILED)
        ->and($wrongStage->message)->toBe('That stage does not belong to the deal\'s pipeline.')
        ->and($deal->refresh()->pipeline_id)->toBe($pipeline->id)
        ->and($noDeal->message)->toBe('There is no deal with that id.');
});

it('closes a deal as lost with the reason given', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = dealIn($pipeline);
    $lost = $pipeline->stages->firstWhere('is_closed_lost', true);

    app(ActionExecutor::class)->run('deals.move_stage', Actor::user(1), ['deal_id' => $deal->id, 'stage_id' => $lost->id, 'lost_reason' => 'Price']);

    expect($deal->refresh()->status)->toBe(DealStatus::Lost)->and($deal->lost_reason)->toBe('Price');
});
