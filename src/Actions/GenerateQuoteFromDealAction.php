<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;

class GenerateQuoteFromDealAction
{
    /**
     * Generate a new Quote from a Deal and snapshot its product line items.
     */
    public function execute(
        Deal $deal,
        ?string $title = null,
        ?string $terms = null,
        ?string $notes = null,
        ?CarbonInterface $expiresAt = null,
        int|string|null $userId = null,
        ?string $internalNotes = null
    ): Quote {
        return DB::transaction(function () use ($deal, $title, $terms, $notes, $expiresAt, $userId, $internalNotes): Quote {
            /** @var Quote $quote */
            $quote = Quote::create([
                'deal_id' => $deal->id,
                'title' => $title ?? "Quote for {$deal->name}",
                'status' => QuoteStatus::Draft,
                'subtotal' => 0.00,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'total_amount' => 0.00,
                'currency' => $deal->currency,
                'terms' => $terms ?? 'Payment due net 30 days from signature.',
                'notes' => $notes,
                'internal_notes' => $internalNotes,
                'expires_at' => $expiresAt ?? now()->addDays(30),
                'user_id' => $userId ?? $deal->owner_id ?? auth()->id(),
            ]);

            $subtotal = 0.0;

            foreach ($deal->products as $product) {
                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'description' => $product->description,
                    'unit_price' => $product->unit_price,
                    'quantity' => $product->quantity,
                    'discount_percent' => $product->discount_percent,
                    'total_price' => $product->total_price,
                    'sort_order' => $product->sort_order,
                ]);

                $subtotal += $product->total_price;
            }

            // If deal had no products but had an aggregate amount, add as a single line item
            if ($deal->products->isEmpty() && (float) $deal->amount > 0) {
                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'name' => $deal->name,
                    'unit_price' => $deal->amount,
                    'quantity' => 1,
                    'discount_percent' => 0.00,
                    'total_price' => $deal->amount,
                    'sort_order' => 0,
                ]);

                $subtotal = (float) $deal->amount;
            }

            $quote->updateQuietly([
                'subtotal' => round($subtotal, 2),
                'total_amount' => round($subtotal, 2),
            ]);

            return $quote;
        });
    }
}
