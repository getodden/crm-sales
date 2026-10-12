<?php

declare(strict_types=1);

use Odden\Core\Models\Contact;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

it('limits the pipeline forecast to the visible deals', function (): void {
    $visible = Deal::factory()->create(['amount' => 1000, 'status' => 'open']);
    Deal::factory()->create(['amount' => 5000, 'status' => 'open']);

    $all = app(CalculatePipelineForecastAction::class)->execute();
    $scoped = app(CalculatePipelineForecastAction::class)->execute(visibleDeals: Deal::query()->whereKey($visible->id));

    expect($all['open_count'])->toBe(2)
        ->and($scoped['open_count'])->toBe(1)
        ->and($scoped['open_value'])->toBe(1000.0);
});

it('processes only the given sequences', function (): void {
    $make = fn (string $name): SalesSequence => SalesSequence::query()->create([
        'name' => $name,
        'is_active' => true,
        'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
    ]);

    $mine = $make('Mine');
    $other = $make('Other');

    foreach ([$mine, $other] as $sequence) {
        SalesSequenceEnrollment::query()->create([
            'sequence_id' => $sequence->id,
            'contact_id' => Contact::factory()->create()->id,
            'current_step' => 1,
            'status' => 'active',
            'next_step_due_at' => now()->toDateString(),
        ]);
    }

    $stats = app(ProcessCadencesAction::class)->execute([$mine->id]);

    expect($stats['processed'])->toBe(1);
});
