<?php

declare(strict_types=1);

namespace Odden\Sales\Screens;

use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\SalesSequence;

final class SalesScreens
{
    private const MODULE = 'sales';

    public static function register(ScreenRegistry $screens): void
    {
        $screens->defineGroup('Sales', 20);

        $screens->requireViewAny('executive-overview', self::MODULE, Deal::class, SalesQuota::class);

        $dealScreens = Screen::forModel('deals', Deal::class, 'Deals', 'Sales', 1, module: self::MODULE, pages: ['index', 'board', 'create', 'view', 'edit']);

        $screens->define(...[
            Screen::page('sales-overview', 'Sales overview', 'Sales', 0, module: self::MODULE, viewAnyOf: [Contact::class, Deal::class, Quote::class, SalesSequence::class]),
            ...array_map(fn (Screen $screen): Screen => $screen->key === 'deals.board' ? $screen->with(label: 'Pipeline board') : $screen, $dealScreens),
            ...Screen::forModel('quotes', Quote::class, 'Quotes', 'Sales', 2, module: self::MODULE),
            ...Screen::forModel('quotas', SalesQuota::class, 'Quotas', 'Sales', 3, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('sales-email-templates', SalesEmailTemplate::class, 'Sales email templates', 'Sales', 4, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('sequences', SalesSequence::class, 'Cadences', 'Sales', 5, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('playbooks', SalesPlaybook::class, 'Playbooks', 'Sales', 6, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('meeting-links', SalesMeetingLink::class, 'Meeting links', 'Sales', 7, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('lead-routing-rules', LeadRoutingRule::class, 'Lead routing rules', 'Sales', 8, module: self::MODULE, pages: ['index', 'create', 'edit']),
            ...Screen::forModel('pipelines', Pipeline::class, 'Pipelines', 'Settings', 2, module: self::MODULE, pages: ['index', 'create', 'edit']),
        ]);
    }
}
