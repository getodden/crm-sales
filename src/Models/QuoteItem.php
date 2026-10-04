<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteLockedException;

/**
 * @property int $id
 * @property int $quote_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $description
 * @property float $unit_price
 * @property float $quantity
 * @property float $discount_percent
 * @property float $total_price
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Quote $quote
 */
class QuoteItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'name',
        'sku',
        'description',
        'unit_price',
        'quantity',
        'discount_percent',
        'total_price',
        'sort_order',
    ];

    /**
     * Default attribute values; a line item without a quantity counts as one unit.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'quantity' => 1,
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.quote_items', 'odden_quote_items');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'total_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            self::ensureQuoteIsOpen($item);

            $subtotal = (float) $item->quantity * (float) $item->unit_price;
            $discountMultiplier = 1 - ((float) $item->discount_percent / 100);
            $item->total_price = round(max(0, $subtotal * $discountMultiplier), 2);
        });

        static::saved(function (self $item): void {
            $item->quote->recalculateTotals();
        });

        static::deleting(function (self $item): void {
            self::ensureQuoteIsOpen($item);
        });

        static::deleted(function (self $item): void {
            $item->quote->recalculateTotals();
        });
    }

    /**
     * Lines of a quote the customer has signed are part of what they signed.
     *
     * @throws QuoteLockedException
     */
    private static function ensureQuoteIsOpen(self $item): void
    {
        if ($item->quote->status === QuoteStatus::Accepted) {
            throw QuoteLockedException::signed();
        }
    }

    /**
     * The quote this item belongs to.
     *
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }
}
