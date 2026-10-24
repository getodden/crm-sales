<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Encryption\BusinessDataConverter;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Models\PropertyHistory;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

function businessDataOn(bool $on = true): void
{
    config()->set('odden-core.encryption.business_data', $on);
}

/** @return array{0: Pipeline, 1: Collection} */
function dealsWorth(array $amounts, ?Pipeline $pipeline = null): array
{
    $pipeline ??= Pipeline::factory()->withStages()->create();
    $stage = $pipeline->stages->firstWhere('code', 'proposal_sent');
    $deals = collect($amounts)->map(fn ($amount) => Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'amount' => $amount, 'currency' => 'USD']));

    return [$pipeline, $deals];
}

function storedDeal(int $id): object
{
    return DB::table('odden_deals')->where('id', $id)->first();
}

it('stores deal amounts plain while the setting is off', function (): void {
    [, $deals] = dealsWorth([1200]);

    $row = storedDeal($deals[0]->id);

    expect((float) $row->amount)->toBe(1200.0)->and($row->amount_encrypted)->toBeNull();
});

it('stores deal amounts encrypted when the setting is on, and still reads them', function (): void {
    businessDataOn();
    [, $deals] = dealsWorth([1234.5]);

    $row = storedDeal($deals[0]->id);

    expect((float) $row->amount)->toBe(0.0)
        ->and(app(FieldEncrypter::class)->isEncrypted($row->amount_encrypted))->toBeTrue()
        ->and($row->amount_encrypted)->not->toContain('1234')
        ->and($deals[0]->fresh()->amount)->toBe('1234.50');
});

it('converts both ways, idempotently, and reads correctly in between', function (): void {
    [, $deals] = dealsWorth([100, 250.25, 40]);
    $converter = app(BusinessDataConverter::class);

    businessDataOn();
    expect($deals[1]->fresh()->amount)->toBe('250.25')
        ->and($converter->pending(true)['deals.amount'])->toBe(3);

    expect($converter->convert(true)['deals.amount'])->toBe(3)
        ->and($converter->convert(true)['deals.amount'])->toBe(0)
        ->and($converter->pending(true)['deals.amount'])->toBe(0)
        ->and((float) storedDeal($deals[1]->id)->amount)->toBe(0.0)
        ->and($deals[1]->fresh()->amount)->toBe('250.25');

    businessDataOn(false);
    expect($converter->convert(false)['deals.amount'])->toBe(3)
        ->and($converter->convert(false)['deals.amount'])->toBe(0)
        ->and(storedDeal($deals[1]->id)->amount_encrypted)->toBeNull()
        ->and((float) storedDeal($deals[1]->id)->amount)->toBe(250.25);
});

it('converts only the rows its scope allows', function (): void {
    [, $deals] = dealsWorth([10, 20]);
    businessDataOn();

    $converted = app(BusinessDataConverter::class)->convert(true, fn ($query, string $table) => $query->where($table.'.id', $deals[0]->id));

    expect($converted['deals.amount'])->toBe(1)
        ->and(storedDeal($deals[0]->id)->amount_encrypted)->not->toBeNull()
        ->and(storedDeal($deals[1]->id)->amount_encrypted)->toBeNull()
        ->and((float) storedDeal($deals[1]->id)->amount)->toBe(20.0);
});

it('gives the same forecast and totals plain, encrypted and half converted', function (): void {
    [$pipeline] = dealsWorth([100000, 50000, 2500.5, 700]);
    $action = new CalculatePipelineForecastAction;
    $amounts = app(EncryptedAmounts::class);
    $total = fn () => $amounts->sum(Deal::query(), 'amount');

    $plain = [$action->execute($pipeline->id), $total(), $amounts->average(Deal::query(), 'amount')];

    businessDataOn();
    app(BusinessDataConverter::class)->convert(true, fn ($query, string $table) => $query->whereIn($table.'.id', Deal::query()->limit(2)->pluck('id')));
    $half = [$action->execute($pipeline->id), $total(), $amounts->average(Deal::query(), 'amount')];

    app(BusinessDataConverter::class)->convert(true);
    $encrypted = [$action->execute($pipeline->id), $total(), $amounts->average(Deal::query(), 'amount')];

    expect($half)->toEqual($plain)->and($encrypted)->toEqual($plain)
        ->and($plain[1])->toBe(153200.5);
});

it('orders and cuts to the top figures the same plain and encrypted', function (): void {
    [, $deals] = dealsWorth([30, 900, 12000, 450]);
    $amounts = app(EncryptedAmounts::class);
    $top = fn () => $amounts->top(Deal::query(), 'amount', limit: 3, atLeast: 100)->get()->pluck('id')->all();

    $plain = $top();
    expect($plain)->toBe([$deals[2]->id, $deals[1]->id, $deals[3]->id]);

    businessDataOn();
    app(BusinessDataConverter::class)->convert(true);

    expect($top())->toBe($plain)
        ->and($amounts->orderedIds(Deal::query(), 'amount', descending: false))->toBe([$deals[0]->id, $deals[3]->id, $deals[1]->id, $deals[2]->id]);
});

it('refuses to aggregate encrypted figures above the fallback limit', function (): void {
    dealsWorth([1, 2, 3]);
    businessDataOn();
    app(BusinessDataConverter::class)->convert(true);
    app()->bind(FallbackLimit::class, fn () => new class extends FallbackLimit
    {
        public function records(): int
        {
            return 2;
        }
    });
    app()->forgetInstance(EncryptedAmounts::class);

    expect(fn () => app(EncryptedAmounts::class)->sum(Deal::query(), 'amount'))->toThrow(TooManyToFilter::class);
});

it('refuses a read without the companion column in a workspace that encrypts', function (): void {
    businessDataOn();
    [, $deals] = dealsWorth([500]);

    expect(fn () => Deal::query()->select(['id', 'amount'])->find($deals[0]->id)->amount)->toThrow(LogicException::class);
});

it('records the history of an encrypted amount with the real figures, encrypted at rest', function (): void {
    businessDataOn();
    [, $deals] = dealsWorth([10000]);
    $deals[0]->update(['amount' => 25000]);

    $rows = PropertyHistory::query()->where(['auditable_type' => $deals[0]->getMorphClass(), 'auditable_id' => $deals[0]->id, 'property_name' => 'amount'])->get();

    expect($rows->contains(fn (PropertyHistory $row): bool => $row->old_value === '10000.00' && $row->new_value === '25000.00'))->toBeTrue()
        ->and(PropertyHistory::query()->where('property_name', 'amount_encrypted')->exists())->toBeFalse();

    $stored = DB::table('odden_property_history')->where('property_name', 'amount')->pluck('new_value')->implode(' ');
    expect($stored)->not->toContain('25000');
});

it('keeps working without an encryption key of its own', function (): void {
    expect(config('odden-core.encryption.key'))->toBeNull();

    businessDataOn();
    [, $deals] = dealsWorth([75]);

    expect($deals[0]->fresh()->amount)->toBe('75.00');
});

it('is not tempted by a raw sum of the amount column in the application code', function (): void {
    $offenders = [];

    foreach ([dirname(__DIR__, 2).'/sales/src', dirname(__DIR__, 2).'/filament/src'] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match("/(->(sum|avg|max|min)\\(\\s*'(amount|target_amount)'|orderBy(Desc)?\\(\\s*'(amount|target_amount)')/", (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
