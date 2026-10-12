<?php

declare(strict_types=1);

use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Actions\CalculateQuotaAttainmentAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Support\Money;
use Odden\Sales\Tests\Fixtures\User;

it('never adds amounts across currencies in the pipeline forecast', function (): void {
    Deal::factory()->create(['amount' => 1000, 'currency' => 'USD', 'status' => 'open']);
    Deal::factory()->create(['amount' => 2000, 'currency' => 'USD', 'status' => 'open']);
    Deal::factory()->create(['amount' => 500, 'currency' => 'EUR', 'status' => 'open']);
    Deal::factory()->create(['amount' => 700, 'currency' => 'EUR', 'status' => 'won', 'closed_at' => now()]);

    $forecast = app(CalculatePipelineForecastAction::class)->execute();

    expect($forecast['currency'])->toBe('USD')
        ->and($forecast['open_count'])->toBe(2)
        ->and($forecast['open_value'])->toBe(3000.0)
        ->and($forecast['other_currencies'])->toBe([
            'EUR' => ['open_count' => 1, 'open_value' => 500.0, 'won_count' => 1, 'won_value' => 700.0, 'lost_count' => 0],
        ]);

    $eur = app(CalculatePipelineForecastAction::class)->execute(currency: 'eur');

    expect($eur['currency'])->toBe('EUR')
        ->and($eur['open_value'])->toBe(500.0)
        ->and($eur['won_value'])->toBe(700.0)
        ->and($eur['other_currencies']['USD']['open_value'])->toBe(3000.0);
});

it('reports in the only currency in use when the default is not used', function (): void {
    Deal::factory()->create(['amount' => 800, 'currency' => 'EUR', 'status' => 'open']);

    $forecast = app(CalculatePipelineForecastAction::class)->execute();

    expect($forecast['currency'])->toBe('EUR')
        ->and($forecast['open_value'])->toBe(800.0)
        ->and($forecast['other_currencies'])->toBe([]);
});

it('counts only deals in the quota currency towards attainment', function (): void {
    $user = User::factory()->create();
    $quota = SalesQuota::query()->create([
        'user_id' => $user->id,
        'target_amount' => 10000,
        'currency' => 'EUR',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    Deal::factory()->create(['owner_id' => $user->id, 'amount' => 4000, 'currency' => 'EUR', 'status' => 'won', 'closed_at' => now()]);
    Deal::factory()->create(['owner_id' => $user->id, 'amount' => 9000, 'currency' => 'USD', 'status' => 'won', 'closed_at' => now()]);

    $result = app(CalculateQuotaAttainmentAction::class)->execute($quota);

    expect($result['currency'])->toBe('EUR')
        ->and($result['won_amount'])->toBe(4000.0)
        ->and($result['attainment_percent'])->toBe(40.0)
        ->and($result['excluded_deal_count'])->toBe(1);
});

it('formats totals per currency', function (): void {
    $deals = [
        new Deal(['amount' => 100, 'currency' => 'USD']),
        new Deal(['amount' => 50, 'currency' => 'EUR']),
        new Deal(['amount' => 25, 'currency' => 'USD']),
    ];

    expect(Money::formatTotals($deals))->toBe('€50.00 + $125.00')
        ->and(Money::prefix('CHF'))->toBe('CHF ')
        ->and(Money::prefix('eur'))->toBe('€');
});
