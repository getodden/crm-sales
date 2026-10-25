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
use Odden\Core\Areas\ProtectedByArea;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Encryption\Index\BlindIndexed;
use Odden\Core\Encryption\Index\HasBlindIndexes;
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
 * @property string|null $notes Shown to the customer on the quote page.
 * @property string|null $internal_notes Never rendered publicly.
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
class Quote extends Model implements BlindIndexed
{
    use HasBlindIndexes;

    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    use ProtectedByArea;
    use SoftDeletes;

    /**
     * What the customer's signature covers: none of it changes once the quote is accepted.
     */
    private const SIGNED_ATTRIBUTES = [
        'status', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'currency', 'deal_id',
        'accepted_at', 'signed_by_name', 'signed_by_email',
    ];

    /**
     * Set while accept() records the signature: the only way a quote becomes accepted.
     */
    private bool $signing = false;

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
        'internal_notes',
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
            'title' => EncryptedString::class.':quotes.title',
            'notes' => EncryptedString::class.':quotes.notes',
            'internal_notes' => EncryptedString::class.':quotes.internal_notes',
            'signed_by_name' => EncryptedString::class.':quotes.signed_by_name',
            'signed_by_email' => EncryptedString::class.':quotes.signed_by_email',
        ];
    }

    /**
     * The title ("Quote for {deal name}") is found again through the search index: by word and by the start of a word.
     *
     * @return array<string, array<string, string|true>>
     */
    public static function blindIndexedColumns(): array
    {
        return ['title' => ['word' => true, 'prefix' => true]];
    }

    public static function blindIndexPrefix(): string
    {
        return 'quotes';
    }

    /**
     * Quotes with a word of this text at the start of a word of the title (every word typed must match).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereTitleStartsWith(Builder $query, string $text): Builder
    {
        return $this->scopeWhereBlindWords($query, ['title'], $text);
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

        // The customer's signature covers the figures, so they are fixed once the quote is accepted, and so is the
        // acceptance itself. A quote only becomes accepted by being signed, through accept().
        static::updating(function (self $quote): void {
            if ($quote->getOriginal('status') === QuoteStatus::Accepted && $quote->isDirty(self::SIGNED_ATTRIBUTES)) {
                throw QuoteLockedException::signed();
            }

            if ($quote->isDirty('status') && $quote->status === QuoteStatus::Accepted && ! $quote->signing) {
                throw QuoteLockedException::unsigned();
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
     * The quote's value without tax: the subtotal after the discount.
     */
    public function netAmount(): float
    {
        return round(max(0, (float) $this->total_amount - (float) $this->tax_amount), 2);
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

        $this->signing = true;

        try {
            $this->update([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
                'signed_by_name' => $name,
                'signed_by_email' => $email,
            ]);
        } finally {
            $this->signing = false;
        }

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
