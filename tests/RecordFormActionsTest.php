<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Exceptions\CrossTenantException;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Actions\CreateDealAction;
use Odden\Sales\Actions\CreateMeetingLinkAction;
use Odden\Sales\Actions\CreateQuoteAction;
use Odden\Sales\Actions\DeleteMeetingLinkAction;
use Odden\Sales\Actions\DeleteQuoteAction;
use Odden\Sales\Actions\SaveDealProductAction;
use Odden\Sales\Actions\SavePipelineAction;
use Odden\Sales\Actions\SavePipelineStageAction;
use Odden\Sales\Actions\SaveQuoteItemsAction;
use Odden\Sales\Actions\SaveStageAutomationsAction;
use Odden\Sales\Actions\UpdateDealAction;
use Odden\Sales\Actions\UpdateMeetingLinkAction;
use Odden\Sales\Actions\UpdateQuoteAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\DealStageMismatchException;
use Odden\Sales\Exceptions\MeetingLinkInUseException;
use Odden\Sales\Exceptions\QuoteLockedException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\StageAutomation;
use Odden\Sales\Tests\Fixtures\User;

function actingInSalesTenantOne(): void
{
    app()->instance(TenantContext::class, new class implements TenantContext
    {
        public function id(): int|string|null
        {
            return 1;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users;
        }
    });
}

function inAnotherTenant(Model $record): Model
{
    $record->setRawAttributes([...$record->getAttributes(), 'tenant_id' => 2]);

    return $record;
}

it('creates a deal open with its first stage history, whatever the form says', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();

    $deal = app(CreateDealAction::class)->execute([
        'name' => 'Big one',
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages[0]->id,
        'status' => 'won',
        'closed_at' => now(),
        'lost_reason' => 'no',
        'amount' => 100,
    ], 9);

    $history = DealStageHistory::query()->where('deal_id', $deal->id)->get();

    expect($deal->status)->toBe(DealStatus::Open)
        ->and($deal->closed_at)->toBeNull()
        ->and($deal->lost_reason)->toBeNull()
        ->and($deal->owner_id)->toBe(9)
        ->and($history)->toHaveCount(1)
        ->and($history[0]->from_stage_id)->toBeNull()
        ->and($history[0]->to_stage_id)->toBe($pipeline->stages[0]->id)
        ->and($history[0]->user_id)->toBe(9);
});

it('keeps the owner the form picked', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();

    $deal = app(CreateDealAction::class)->execute(['name' => 'Owned', 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[0]->id, 'owner_id' => 4], 9);

    expect($deal->owner_id)->toBe(4);
});

it('updates a deal but never its pipeline, stage or status, and keeps the properties it was not sent', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[0]->id, 'name' => 'Old', 'properties' => ['a' => 1, 'b' => 2]]);

    app(UpdateDealAction::class)->execute($deal, [
        'name' => 'New',
        'pipeline_id' => 999,
        'stage_id' => $pipeline->stages[4]->id,
        'status' => 'won',
        'closed_at' => now(),
        'properties' => ['a' => 5],
    ]);

    $fresh = $deal->fresh();

    expect($fresh->name)->toBe('New')
        ->and($fresh->pipeline_id)->toBe($pipeline->id)
        ->and($fresh->stage_id)->toBe($pipeline->stages[0]->id)
        ->and($fresh->status)->toBe(DealStatus::Open)
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->properties)->toBe(['a' => 5, 'b' => 2]);
});

it('creates and updates a quote', function (): void {
    $deal = Deal::factory()->create();

    $quote = app(CreateQuoteAction::class)->execute(['deal_id' => $deal->id, 'title' => 'First', 'currency' => 'USD']);
    app(UpdateQuoteAction::class)->execute($quote, ['title' => 'Second']);

    expect($quote->quote_number)->not->toBeEmpty()
        ->and($quote->fresh()->title)->toBe('Second');
});

it('saves quote line items: adds, updates, reorders and removes, and ignores an id from another quote', function (): void {
    $quote = Quote::factory()->create();
    $other = Quote::factory()->create();
    $foreign = QuoteItem::create(['quote_id' => $other->id, 'name' => 'Theirs', 'unit_price' => 1, 'quantity' => 1, 'sort_order' => 1]);

    app(SaveQuoteItemsAction::class)->execute($quote, [
        ['id' => null, 'name' => 'First', 'unit_price' => 10, 'quantity' => 1],
        ['id' => null, 'name' => 'Second', 'unit_price' => 20, 'quantity' => 2],
    ]);

    [$first, $second] = $quote->items()->get()->all();

    app(SaveQuoteItemsAction::class)->execute($quote, [
        ['id' => (string) $second->id, 'name' => 'Second renamed', 'unit_price' => 20, 'quantity' => 2],
        ['id' => (string) $foreign->id, 'name' => 'Forged', 'unit_price' => 5, 'quantity' => 1],
    ]);

    $names = $quote->items()->pluck('name')->all();

    expect($names)->toBe(['Second renamed', 'Forged'])
        ->and($quote->items()->first()->is($second))->toBeTrue()
        ->and($foreign->fresh()->name)->toBe('Theirs')
        ->and(QuoteItem::query()->whereKey($first->id)->exists())->toBeFalse();
});

it('saves a deal product and keeps the deal amount in step', function (): void {
    $deal = Deal::factory()->create(['amount' => 0]);

    $product = app(SaveDealProductAction::class)->execute($deal, ['name' => 'Licence', 'unit_price' => 100, 'quantity' => 2, 'discount_percent' => 0]);
    app(SaveDealProductAction::class)->execute($deal, ['name' => 'Licence', 'unit_price' => 100, 'quantity' => 3, 'discount_percent' => 0], $product);

    expect($deal->products()->count())->toBe(1)
        ->and((float) $deal->fresh()->amount)->toBe(300.0);
});

it('keeps a single default pipeline', function (): void {
    $first = app(SavePipelineAction::class)->execute(['name' => 'First', 'code' => 'first', 'is_default' => true]);
    $second = app(SavePipelineAction::class)->execute(['name' => 'Second', 'code' => 'second', 'is_default' => true]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();

    app(SavePipelineAction::class)->execute(['name' => 'First again', 'is_default' => true], $first->fresh());

    expect($first->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_default)->toBeFalse();
});

it('saves pipeline stages and their automations', function (): void {
    $pipeline = Pipeline::factory()->create();

    $stage = app(SavePipelineStageAction::class)->execute($pipeline, ['name' => 'Intro', 'code' => 'intro', 'probability' => 10, 'sort_order' => 1]);
    app(SavePipelineStageAction::class)->execute($pipeline, ['name' => 'Intro call'], $stage);

    app(SaveStageAutomationsAction::class)->execute($stage, [
        ['id' => null, 'name' => 'Task', 'action_type' => 'create_task', 'is_active' => true],
        ['id' => null, 'name' => 'Guard', 'action_type' => 'create_task', 'is_active' => true],
    ]);
    $guard = StageAutomation::query()->where('name', 'Guard')->firstOrFail();

    app(SaveStageAutomationsAction::class)->execute($stage, [['id' => (string) $guard->id, 'name' => 'Guard renamed', 'action_type' => 'create_task', 'is_active' => false]]);

    expect($stage->fresh()->name)->toBe('Intro call')
        ->and($pipeline->stages()->count())->toBe(1)
        ->and($stage->automations()->pluck('name')->all())->toBe(['Guard renamed']);
});

it('creates a meeting link and deletes one without bookings', function (): void {
    $user = User::factory()->create();

    $link = app(CreateMeetingLinkAction::class)->execute([
        'slug' => 'demo',
        'title' => 'Demo',
        'duration_minutes' => 30,
        'working_hours' => [],
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    expect(SalesMeetingLink::query()->count())->toBe(1)
        ->and(app(DeleteMeetingLinkAction::class)->execute($link))->toBeTrue()
        ->and(SalesMeetingLink::query()->count())->toBe(0);
});

it('refuses to delete a meeting link with upcoming bookings and keeps it', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2030-01-06 08:00:00', 'UTC'));
    $host = User::factory()->create();
    $link = app(CreateMeetingLinkAction::class)->execute([
        'slug' => 'busy',
        'title' => 'Busy',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-10:30']],
        'user_id' => $host->id,
        'is_active' => true,
    ]);
    app(BookMeetingAction::class)->execute($link, 'Dana Scully', 'dana@example.com', Carbon::parse('2030-01-07 09:30'));

    expect(fn () => app(DeleteMeetingLinkAction::class)->execute($link))->toThrow(MeetingLinkInUseException::class)
        ->and(SalesMeetingLink::query()->whereKey($link->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('normalises the working hours of a new meeting link', function (): void {
    $link = app(CreateMeetingLinkAction::class)->execute([
        'slug' => 'tidy',
        'title' => 'Tidy',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => [' 09:00-10:00 ', ''], 'tuesday' => []],
        'user_id' => User::factory()->create()->id,
        'is_active' => true,
    ]);

    expect($link->fresh()->working_hours)->toBe(['monday' => ['09:00-10:00']]);
});

it('refuses a new deal in a closed stage or a stage of another pipeline', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $other = Pipeline::factory()->withStages()->create();

    expect(fn () => app(CreateDealAction::class)->execute(['name' => 'Won', 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[4]->id]))
        ->toThrow(DealStageMismatchException::class)
        ->and(fn () => app(CreateDealAction::class)->execute(['name' => 'Elsewhere', 'pipeline_id' => $pipeline->id, 'stage_id' => $other->stages[0]->id]))
        ->toThrow(DealStageMismatchException::class)
        ->and(Deal::query()->count())->toBe(0)
        ->and(DealStageHistory::query()->count())->toBe(0);
});

it('refuses to edit a deal product or a stage through another deal or pipeline', function (): void {
    $deal = Deal::factory()->create();
    $other = Deal::factory()->create(['amount' => 0]);
    $product = app(SaveDealProductAction::class)->execute($other, ['name' => 'Theirs', 'unit_price' => 10, 'quantity' => 1, 'discount_percent' => 0]);
    $pipeline = Pipeline::factory()->create();
    $otherPipeline = Pipeline::factory()->create();
    $stage = app(SavePipelineStageAction::class)->execute($otherPipeline, ['name' => 'Theirs', 'code' => 'theirs', 'probability' => 10, 'sort_order' => 1]);

    expect(fn () => app(SaveDealProductAction::class)->execute($deal, ['name' => 'Taken'], $product))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SavePipelineStageAction::class)->execute($pipeline, ['name' => 'Taken'], $stage))->toThrow(ModelNotFoundException::class)
        ->and(DealProduct::query()->whereKey($product->id)->value('name'))->toBe('Theirs')
        ->and($stage->fresh()->name)->toBe('Theirs');
});

it('puts a quote in its deal currency for the deal owner and keeps its owner and currency on edit', function (): void {
    $deal = Deal::factory()->create(['currency' => 'EUR', 'owner_id' => 4]);
    $usdDeal = Deal::factory()->create(['currency' => 'GBP', 'owner_id' => 5]);

    $quote = app(CreateQuoteAction::class)->execute(['deal_id' => $deal->id, 'title' => 'First', 'currency' => 'USD'], 9);

    expect($quote->currency)->toBe('EUR')
        ->and($quote->user_id)->toBe(4);

    app(UpdateQuoteAction::class)->execute($quote, ['title' => 'Second', 'currency' => 'USD', 'user_id' => 99]);

    expect($quote->fresh()->currency)->toBe('EUR')
        ->and($quote->fresh()->user_id)->toBe(4);

    app(UpdateQuoteAction::class)->execute($quote, ['deal_id' => $usdDeal->id]);

    expect($quote->fresh()->currency)->toBe('GBP')
        ->and($quote->fresh()->user_id)->toBe(4);
});

it('edits and adds quotes on a deal in the trash', function (): void {
    $deal = Deal::factory()->create(['currency' => 'EUR', 'owner_id' => 4]);
    $quote = app(CreateQuoteAction::class)->execute(['deal_id' => $deal->id, 'title' => 'First']);
    $deal->delete();

    app(UpdateQuoteAction::class)->execute($quote->fresh(), ['title' => 'Second']);
    $added = app(CreateQuoteAction::class)->execute(['deal_id' => $deal->id, 'title' => 'Third']);

    expect($quote->fresh()->title)->toBe('Second')
        ->and($quote->fresh()->currency)->toBe('EUR')
        ->and($added->currency)->toBe('EUR')
        ->and($added->user_id)->toBe(4);
});

it('deletes a quote that was not signed and keeps one that was', function (): void {
    $draft = Quote::factory()->create();
    $signed = Quote::factory()->create();
    Quote::query()->whereKey($signed->id)->update(['status' => QuoteStatus::Accepted]);

    expect(app(DeleteQuoteAction::class)->execute($draft))->toBeTrue()
        ->and(fn () => app(DeleteQuoteAction::class)->execute($signed->fresh()))->toThrow(QuoteLockedException::class)
        ->and(Quote::query()->pluck('id')->all())->toBe([$signed->id]);
});

it('normalises the working hours of an edited meeting link and falls back to the default hours when none are left', function (): void {
    $link = app(CreateMeetingLinkAction::class)->execute([
        'slug' => 'edit',
        'title' => 'Edit',
        'duration_minutes' => 30,
        'working_hours' => ['monday' => ['09:00-10:00']],
        'user_id' => User::factory()->create()->id,
        'is_active' => true,
    ]);

    app(UpdateMeetingLinkAction::class)->execute($link, ['title' => 'Tidied', 'working_hours' => ['monday' => [' 09:00-11:00 ', ''], 'tuesday' => []]]);

    expect($link->fresh()->title)->toBe('Tidied')
        ->and($link->fresh()->working_hours)->toBe(['monday' => ['09:00-11:00']]);

    app(UpdateMeetingLinkAction::class)->execute($link, ['title' => 'Retitled']);

    expect($link->fresh()->title)->toBe('Retitled')
        ->and($link->fresh()->working_hours)->toBe(['monday' => ['09:00-11:00']]);

    app(UpdateMeetingLinkAction::class)->execute($link, ['working_hours' => ['monday' => ['']]]);

    expect($link->fresh()->working_hours)->toBeNull();
});

it('refuses to write a record of another tenant', function (): void {
    $pipeline = Pipeline::factory()->withStages()->create();
    $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages[0]->id, 'name' => 'Mine']);
    $quote = Quote::factory()->create(['title' => 'Mine']);
    $stage = $pipeline->stages[0];
    $link = app(CreateMeetingLinkAction::class)->execute(['slug' => 'mine', 'title' => 'Mine', 'duration_minutes' => 30, 'working_hours' => [], 'user_id' => User::factory()->create()->id, 'is_active' => true]);
    $foreignDeal = inAnotherTenant(clone $deal);
    $foreignQuote = inAnotherTenant(clone $quote);
    $foreignPipeline = inAnotherTenant(clone $pipeline);
    $foreignStage = inAnotherTenant(clone $stage);
    $foreignLink = inAnotherTenant(clone $link);
    actingInSalesTenantOne();

    expect(fn () => app(UpdateDealAction::class)->execute($foreignDeal, ['name' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(fn () => app(UpdateQuoteAction::class)->execute($foreignQuote, ['title' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(fn () => app(DeleteQuoteAction::class)->execute($foreignQuote))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SaveQuoteItemsAction::class)->execute($foreignQuote, [['id' => null, 'name' => 'Taken', 'unit_price' => 1, 'quantity' => 1]]))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SaveDealProductAction::class)->execute($foreignDeal, ['name' => 'Taken', 'unit_price' => 1, 'quantity' => 1, 'discount_percent' => 0]))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SavePipelineAction::class)->execute(['name' => 'Taken'], $foreignPipeline))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SavePipelineStageAction::class)->execute($foreignPipeline, ['name' => 'Taken'], $foreignStage))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SaveStageAutomationsAction::class)->execute($foreignStage, []))->toThrow(CrossTenantException::class)
        ->and(fn () => app(UpdateMeetingLinkAction::class)->execute($foreignLink, ['title' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(fn () => app(DeleteMeetingLinkAction::class)->execute($foreignLink))->toThrow(CrossTenantException::class)
        ->and($deal->fresh()->name)->toBe('Mine')
        ->and($quote->fresh()->title)->toBe('Mine')
        ->and(SalesMeetingLink::query()->value('title'))->toBe('Mine');
});

it('creates and updates a quote without letting a caller set the signature, token or number', function (): void {
    $deal = Deal::factory()->create();

    $quote = app(CreateQuoteAction::class)->execute([
        'deal_id' => $deal->id,
        'title' => 'Forged',
        'status' => QuoteStatus::Accepted,
        'accepted_at' => now(),
        'signed_by_name' => 'Jane',
        'signed_by_email' => 'jane@example.com',
        'public_token' => 'known',
        'quote_number' => 'Q-FORGED',
    ]);

    expect($quote->status)->not->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_at)->toBeNull()
        ->and($quote->signed_by_name)->toBeNull()
        ->and($quote->public_token)->not->toBe('known')
        ->and($quote->quote_number)->not->toBe('Q-FORGED');

    $number = $quote->quote_number;
    $token = $quote->public_token;

    app(UpdateQuoteAction::class)->execute($quote, ['title' => 'Edited', 'signed_by_name' => 'Jane', 'accepted_at' => now(), 'public_token' => 'known', 'quote_number' => 'Q-FORGED']);

    $fresh = $quote->fresh();
    expect($fresh->title)->toBe('Edited')
        ->and($fresh->signed_by_name)->toBeNull()
        ->and($fresh->accepted_at)->toBeNull()
        ->and($fresh->public_token)->toBe($token)
        ->and($fresh->quote_number)->toBe($number);
});

it('keeps a child on its parent when a save carries another parent key', function (): void {
    $deal = Deal::factory()->create(['amount' => 0]);
    $otherDeal = Deal::factory()->create(['amount' => 0]);
    $pipeline = Pipeline::factory()->create();
    $otherPipeline = Pipeline::factory()->create();
    $quote = Quote::factory()->create();
    $otherQuote = Quote::factory()->create();

    $product = app(SaveDealProductAction::class)->execute($deal, ['name' => 'Licence', 'unit_price' => 1, 'quantity' => 1, 'discount_percent' => 0]);
    app(SaveDealProductAction::class)->execute($deal, ['name' => 'Renamed', 'deal_id' => $otherDeal->id], $product);

    $stage = app(SavePipelineStageAction::class)->execute($pipeline, ['name' => 'Intro', 'code' => 'intro', 'probability' => 10, 'sort_order' => 1]);
    app(SavePipelineStageAction::class)->execute($pipeline, ['name' => 'Renamed', 'pipeline_id' => $otherPipeline->id], $stage);

    app(SaveQuoteItemsAction::class)->execute($quote, [['id' => null, 'name' => 'Item', 'unit_price' => 1, 'quantity' => 1]]);
    $item = $quote->items()->firstOrFail();
    app(SaveQuoteItemsAction::class)->execute($quote, [['id' => (string) $item->id, 'name' => 'Item', 'unit_price' => 1, 'quantity' => 1, 'quote_id' => $otherQuote->id]]);

    expect((string) $product->fresh()->deal_id)->toBe((string) $deal->id)
        ->and((string) $stage->fresh()->pipeline_id)->toBe((string) $pipeline->id)
        ->and((string) $item->fresh()->quote_id)->toBe((string) $quote->id);
});

it('recalculates quote totals instead of taking them from the caller', function (): void {
    $deal = Deal::factory()->create();

    $quote = app(CreateQuoteAction::class)->execute(['deal_id' => $deal->id, 'title' => 'Totals', 'subtotal' => 999, 'total_amount' => 1]);

    expect((float) $quote->fresh()->total_amount)->toBe(0.0);

    app(UpdateQuoteAction::class)->execute($quote, ['total_amount' => 1, 'subtotal' => 5]);

    expect((float) $quote->fresh()->total_amount)->toBe(0.0)
        ->and((float) $quote->fresh()->subtotal)->toBe(0.0);
});
