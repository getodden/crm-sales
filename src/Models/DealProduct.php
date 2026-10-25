<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Sales\Database\Factories\DealProductFactory;

/**
 * @property int $id
 * @property int $deal_id
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
 * @property CarbonInterface|null $deleted_at
 * @property-read Deal $deal
 */
class DealProduct extends Model
{
    /** @use HasFactory<DealProductFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'deal_id',
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
        return config('odden-sales.tables.products', 'odden_deal_products');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => EncryptedString::class.':deal_products.name',
            'description' => EncryptedString::class.':deal_products.description',
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
        static::saving(function (self $product): void {
            $product->total_price = $product->calculateTotalPrice();
        });

        static::saved(function (self $product): void {
            $product->deal->syncAmountFromProducts();
        });

        static::deleted(function (self $product): void {
            $product->deal->syncAmountFromProducts();
        });

        static::restored(function (self $product): void {
            $product->deal->syncAmountFromProducts();
        });
    }

    /**
     * Calculate line item total price applying quantity and discount.
     */
    public function calculateTotalPrice(): float
    {
        $subtotal = (float) $this->quantity * (float) $this->unit_price;
        $discountMultiplier = 1 - ((float) $this->discount_percent / 100);

        return round(max(0, $subtotal * $discountMultiplier), 2);
    }

    /**
     * Deal this product line item belongs to.
     *
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DealProductFactory
    {
        return DealProductFactory::new();
    }
}
