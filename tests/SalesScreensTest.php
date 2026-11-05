<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Tests\Fixtures\User;

function salesScreens(): array
{
    $screens = [];

    foreach (app(ScreenRegistry::class)->all() as $screen) {
        if ($screen->module === 'sales') {
            $screens[$screen->key] = $screen;
        }
    }

    return $screens;
}

it('defines the sales screens in the sales module', function (): void {
    expect(array_keys(salesScreens()))->toBe([
        'sales-overview',
        'deals.index', 'deals.board', 'deals.create', 'deals.view', 'deals.edit',
        'quotes.index', 'quotes.create', 'quotes.view', 'quotes.edit',
        'quotas.index', 'quotas.create', 'quotas.edit',
        'sales-email-templates.index', 'sales-email-templates.create', 'sales-email-templates.edit',
        'sequences.index', 'sequences.create', 'sequences.edit',
        'playbooks.index', 'playbooks.create', 'playbooks.edit',
        'meeting-links.index', 'meeting-links.create', 'meeting-links.edit',
        'lead-routing-rules.index', 'lead-routing-rules.create', 'lead-routing-rules.edit',
        'pipelines.index', 'pipelines.create', 'pipelines.edit',
    ]);
});

it('labels, groups and orders the sales screens', function (): void {
    $summary = array_map(
        fn (Screen $screen): array => [$screen->label, $screen->group, $screen->sort],
        array_filter(salesScreens(), fn (Screen $screen): bool => in_array($screen->key, [
            'sales-overview', 'deals.index', 'deals.board', 'quotes.index', 'quotas.index', 'sales-email-templates.index', 'sequences.index',
            'playbooks.index', 'meeting-links.index', 'lead-routing-rules.index', 'pipelines.index',
        ], true)),
    );

    expect($summary)->toBe([
        'sales-overview' => ['Sales overview', 'Sales', 0],
        'deals.index' => ['Deals', 'Sales', 1],
        'deals.board' => ['Pipeline board', 'Sales', 1],
        'quotes.index' => ['Quotes', 'Sales', 2],
        'quotas.index' => ['Quotas', 'Sales', 3],
        'sales-email-templates.index' => ['Sales email templates', 'Sales', 4],
        'sequences.index' => ['Cadences', 'Sales', 5],
        'playbooks.index' => ['Playbooks', 'Sales', 6],
        'meeting-links.index' => ['Meeting links', 'Sales', 7],
        'lead-routing-rules.index' => ['Lead routing rules', 'Sales', 8],
        'pipelines.index' => ['Pipelines', 'Settings', 2],
    ]);
});

it('registers alongside the core screens and is frozen', function (): void {
    $registry = app(ScreenRegistry::class);

    expect($registry->has('contacts.index'))->toBeTrue()
        ->and($registry->isFrozen())->toBeTrue();
});

it('requires the sales models for its overview and adds them to the executive overview', function (): void {
    $registry = app(ScreenRegistry::class);
    $policy = new class
    {
        public function viewAny(): bool
        {
            return false;
        }
    };
    Gate::policy(Deal::class, $policy::class);
    $user = new User;

    expect($registry->get('sales-overview')->viewAnyOf)->toBe([Contact::class, Deal::class, Quote::class, SalesSequence::class])
        ->and($registry->can('sales-overview', $user))->toBeFalse()
        ->and($registry->can('executive-overview', $user))->toBeFalse()
        ->and($registry->can('data-quality', $user))->toBeTrue();
});
