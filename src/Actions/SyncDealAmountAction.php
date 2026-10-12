<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Sales\Models\Deal;

class SyncDealAmountAction
{
    /**
     * Recalculate and update the deal aggregate amount from its product line items, while the deal is open.
     */
    public function execute(Deal $deal): Deal
    {
        // A closed deal keeps the amount it closed at (for a won deal, the signed quote's figure).
        if (! $deal->status->isOpen()) {
            return $deal;
        }

        $total = $deal->products()->exists()
            ? (float) $deal->products()->sum('total_price')
            : 0.00;

        $deal->updateQuietly(['amount' => round($total, 2)]);

        return $deal;
    }
}
