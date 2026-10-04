<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Odden\Core\Support\UserModel;
use Odden\Sales\Database\Factories\QuoteFactory;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteLockedException;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;

/**
 * @property int $id
 * @property int $deal_id
 * @property string $quote_number
 * @property string $title
 * @property QuoteStatus $status
 * @property float $subtotal
 * @property float $discount_amount
 * @property float $tax_amount
 * @property float $total_amount
 * @property string $currency
 * @property string|null $terms
 * @property string|null $notes
 * @property string $public_token
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property string|null $signed_by_name
 * @property string|null $signed_by_email
 * @property int|null $user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Deal $deal
 * @property-read Collection<int, QuoteItem> $items
 */
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'deal_id',
        'quote_number',
        'title',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'terms',
        'notes',
        'public_token',
        'expires_at',
        'accepted_at',
        'signed_by_name',
        'signed_by_email',
        'user_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.quotes', 'odden_quotes');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'expires_at' => 'date',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        static::creating(function (self $quote): void {
            if (empty($quote->public_token)) {
                $quote->public_token = Str::random(40);
            }

            if (empty($quote->quote_number)) {
                // Five random characters can repeat; the number is unique, so look for a free one.
                do {
                    $number = 'Q-'.now()->format('Y').'-'.strtoupper(Str::random(5));
                } while (static::query()->withTrashed()->where('quote_number', $number)->exists());

                $quote->quote_number = $number;
            }
        });

        // The customer's signature covers the figures, so they are fixed once the quote is accepted.
        static::updating(function (self $quote): void {
            if ($quote->getOriginal('status') === QuoteStatus::Accepted && $quote->isDirty(['discount_amount', 'tax_amount', 'currency', 'deal_id'])) {
                throw QuoteLockedException::signed();
            }
        });

        // A new discount or tax changes the total, whether or not the quote has items.
        static::saved(function (self $quote): void {
            if ($quote->wasChanged(['discount_amount', 'tax_amount'])) {
                $quote->recalculateTotals();
            }
        });
    }

    /**
     * The deal this quote belongs to.
     *
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * Line items on this quote.
     *
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class, 'quote_id')->orderBy('sort_order');
    }

    /**
     * The creator / sales rep for this quote.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Recalculate totals from quote items.
     */
    public function recalculateTotals(): self
    {
        $subtotal = (float) $this->items()->sum('total_price');
        $net = max(0, $subtotal - (float) $this->discount_amount);
        $total = $net + (float) $this->tax_amount;

        $this->updateQuietly([
            'subtotal' => round($subtotal, 2),
            'total_amount' => round($total, 2),
        ]);

        return $this;
    }

    /**
     * Check if the quote has expired.
     */
    public function isExpired(): bool
    {
        if ($this->status === QuoteStatus::Accepted) {
            return false;
        }

        return $this->status === QuoteStatus::Expired || $this->hasPassedExpiryDate();
    }

    /**
     * Whether the expiry date has passed. A quote stays valid through the whole of its expiry day.
     */
    public function hasPassedExpiryDate(): bool
    {
        return $this->expires_at !== null && $this->expires_at->toDateString() < today()->toDateString();
    }

    /**
     * Whether the quote can still be accepted: not accepted, declined or expired, and not past its expiry date.
     */
    public function isAcceptable(): bool
    {
        return ! $this->status->isTerminal() && ! $this->hasPassedExpiryDate();
    }

    /**
     * Scope query to quotes whose expiry date has passed (the same rule as hasPassedExpiryDate()).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePastExpiryDate(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->whereDate('expires_at', '<', today()->toDateString());
    }

    /**
     * Accept the quote and record digital signature. Only the quote's own state is changed; use
     * AcceptQuoteAction to also close the deal as won.
     *
     * @throws QuoteNotAcceptableException
     */
    public function accept(string $name, string $email): self
    {
        if ($this->status->isTerminal()) {
            throw new QuoteNotAcceptableException("Quote {$this->quote_number} is {$this->status->value} and can no longer be accepted.");
        }

        $this->update([
            'status' => QuoteStatus::Accepted,
            'accepted_at' => now(),
            'signed_by_name' => $name,
            'signed_by_email' => $email,
        ]);

        return $this;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }
}
