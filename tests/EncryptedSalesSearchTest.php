<?php

declare(strict_types=1);

use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Support\EncryptedSalesSearch;
use Odden\Sales\Tests\Fixtures\User;

function dealNamed(string $name): Deal
{
    $pipeline = Pipeline::factory()->withStages()->create();

    return Deal::factory()->create(['name' => $name, 'pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->firstOrFail()->id]);
}

function personHoldingNoSalesAreas(): void
{
    app()->instance(AreaAccess::class, new class implements AreaAccess
    {
        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return false;
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return false;
        }
    });
    app()->forgetInstance(Areas::class);
    test()->actingAs(User::query()->create(['id' => 1, 'name' => 'User 1', 'email' => 'u1@example.com', 'password' => 'x']));
}

it('finds deals by the start of a word of the name', function (): void {
    $renewal = dealNamed('Annual Renewal');
    dealNamed('Pilot Project');

    expect(EncryptedSalesSearch::deals()(Deal::query(), 'ren')->pluck('id')->all())->toBe([$renewal->id]);
});

it('finds quotes by the start of a word of the title', function (): void {
    $match = Quote::factory()->create(['title' => 'Onsite Training']);
    Quote::factory()->create(['title' => 'Hosting']);

    expect(EncryptedSalesSearch::quotes()(Quote::query(), 'train')->pluck('id')->all())->toBe([$match->id]);
});

it('finds quotes by the name of their deal', function (): void {
    $deal = dealNamed('Annual Renewal');
    $match = Quote::factory()->create(['deal_id' => $deal->id]);
    Quote::factory()->create(['deal_id' => dealNamed('Pilot Project')->id]);

    expect(EncryptedSalesSearch::quotesOfDeals()(Quote::query(), 'renew')->pluck('id')->all())->toBe([$match->id]);
});

it('finds nothing when the person lacks the Sales area', function (): void {
    $deal = dealNamed('Annual Renewal');
    Quote::factory()->create(['title' => 'Annual Quote', 'deal_id' => $deal->id]);
    personHoldingNoSalesAreas();

    expect(EncryptedSalesSearch::deals()(Deal::query(), 'annual')->count())->toBe(0)
        ->and(EncryptedSalesSearch::quotes()(Quote::query(), 'annual')->count())->toBe(0)
        ->and(EncryptedSalesSearch::quotesOfDeals()(Quote::query(), 'annual')->count())->toBe(0);
});
