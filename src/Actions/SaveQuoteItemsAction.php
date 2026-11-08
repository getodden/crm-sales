<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Actions\SyncChildRecordsAction;
use Odden\Sales\Models\Quote;

class SaveQuoteItemsAction
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function execute(Quote $quote, array $items): Quote
    {
        app(SyncChildRecordsAction::class)->execute($quote->items(), $items, 'sort_order');

        return $quote;
    }
}
