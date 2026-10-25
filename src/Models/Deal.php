<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Odden\Core\Areas\ProtectedByArea;
use Odden\Core\Encryption\Casts\CustomProperties;
use Odden\Core\Encryption\Casts\EncryptedAmount;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Encryption\Index\BlindIndexed;
use Odden\Core\Encryption\Index\HasBlindIndexes;
use Odden\Core\Encryption\Index\IndexText;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Core\Traits\AuditsProperties;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Core\Traits\HasActivities;
use Odden\Core\Traits\HasAssociations;
use Odden\Core\Traits\HasCustomProperties;
use Odden\Sales\Actions\CalculateDealHealthScoreAction;
use Odden\Sales\Actions\ChangeDealStageAction;
use Odden\Sales\Actions\SyncDealAmountAction;
use Odden\Sales\Database\Factories\DealFactory;
use Odden\Sales\Enums\DealStatus;
use RuntimeException;

/**
 * @property int $id
 * @property int $pipeline_id
 * @property int $stage_id
 * @property string $name
 * @property float $amount
 * @property string $currency
 * @property DealStatus $status
 * @property CarbonInterface|null $expected_close_date
 * @property CarbonInterface|null $closed_at
 * @property string|null $lost_reason
 * @property string|null $lost_notes
 * @property array<string, mixed>|null $properties
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Pipeline $pipeline
 * @property-read PipelineStage $stage
 * @property-read Collection<int, DealProduct> $products
 * @property-read Collection<int, Quote> $quotes
 */
class Deal extends Model implements BlindIndexed
{
    use AuditsProperties;
    use BelongsToTeam;
    use HasActivities;
    use HasAssociations;
    use HasBlindIndexes;
    use HasCustomProperties;

    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use ProtectedByArea;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pipeline_id',
        'stage_id',
        'name',
        'amount',
        'currency',
        'status',
        'expected_close_date',
        'closed_at',
        'lost_reason',
        'lost_notes',
        'properties',
        'owner_id',
        'team_id',
    ];

    /**
     * Default attribute values, so a freshly created deal is usable without a refresh().
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.deals', 'odden_deals');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => EncryptedAmount::class.':deals.amount',
            'status' => DealStatus::class,
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
            'properties' => CustomProperties::class,
            'lost_reason' => EncryptedString::class.':deals.lost_reason',
            'lost_notes' => EncryptedString::class.':deals.lost_notes',
            'name' => EncryptedString::class.':deals.name',
        ];
    }

    /**
     * The deal name is found again through the search index: as a whole, by word and by the start of a word. (A deal is often named
     * after the company or person it is with.)
     *
     * @return array<string, array<string, string|true>>
     */
    public static function blindIndexedColumns(): array
    {
        return ['name' => ['exact' => IndexText::TEXT, 'word' => true, 'prefix' => true]];
    }

    public static function blindIndexPrefix(): string
    {
        return 'deals';
    }

    /**
     * Deals with a word of this text at the start of a word of the name (every word typed must match).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereNameStartsWith(Builder $query, string $text): Builder
    {
        return $this->scopeWhereBlindWords($query, ['name'], $text);
    }

    /**
     * The pipeline this deal belongs to.
     *
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * The current stage of this deal.
     *
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * The sales rep / user who owns this deal.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'owner_id');
    }

    /**
     * History of stage movements for this deal.
     *
     * @return HasMany<DealStageHistory, $this>
     */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(DealStageHistory::class, 'deal_id')->orderBy('entered_at', 'desc');
    }

    /**
     * Contacts associated with this deal.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            config('odden-core.tables.associations', 'odden_associations'),
            'parent_id',
            'child_id'
        )
            ->wherePivot('parent_type', $this->getMorphClass())
            ->wherePivot('child_type', (new Contact)->getMorphClass())
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * Companies associated with this deal.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            config('odden-core.tables.associations', 'odden_associations'),
            'parent_id',
            'child_id'
        )
            ->wherePivot('parent_type', $this->getMorphClass())
            ->wherePivot('child_type', (new Company)->getMorphClass())
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * Product line items attached to this deal.
     *
     * @return HasMany<DealProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(DealProduct::class, 'deal_id')->orderBy('sort_order');
    }

    /**
     * Quotes generated for this deal.
     *
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'deal_id')->orderBy('created_at', 'desc');
    }

    /**
     * Synchronize the aggregate deal amount from line items.
     */
    public function syncAmountFromProducts(): self
    {
        app(SyncDealAmountAction::class)->execute($this);

        return $this;
    }

    /**
     * Number of days this deal has spent in its current stage.
     */
    public function daysInCurrentStage(): int
    {
        /** @var DealStageHistory|null $latestHistory */
        $latestHistory = $this->stageHistory->first() ?? $this->stageHistory()->first();
        $enteredAt = $latestHistory !== null ? $latestHistory->entered_at : ($this->created_at ?? now());

        return (int) $enteredAt->diffInDays(now());
    }

    /**
     * Determine if the deal is rotting / idle past the stage's threshold.
     */
    public function isRotten(): bool
    {
        if (! $this->status->isOpen()) {
            return false;
        }

        $rotDays = $this->stage->rot_after_days;

        if ($rotDays === null || $rotDays <= 0) {
            return false;
        }

        return $this->daysInCurrentStage() >= $rotDays;
    }

    /**
     * Calculate the deal health & win probability score (0-100).
     *
     * @return array{
     *     score: int,
     *     status: 'strong'|'moderate'|'at_risk'|'stalled',
     *     badge_label: string,
     *     badge_color: string,
     *     factors: list<array{name: string, points: int, positive: bool, description: string}>,
     *     recommendations: list<string>
     * }
     */
    public function getHealthScore(): array
    {
        return app(CalculateDealHealthScoreAction::class)->execute($this);
    }

    /**
     * Move deal to another stage.
     */
    public function moveToStage(PipelineStage $stage, int|string|null $userId = null, ?string $lostReason = null, ?string $lostNotes = null): self
    {
        return app(ChangeDealStageAction::class)->execute($this, $stage, $userId, $lostReason, $lostNotes);
    }

    /**
     * Mark deal as Won.
     */
    public function markWon(int|string|null $userId = null): self
    {
        /** @var PipelineStage|null $wonStage */
        $wonStage = $this->pipeline->stages()->where('is_closed_won', true)->first();

        if ($wonStage === null) {
            throw new RuntimeException("Pipeline [{$this->pipeline->name}] has no closed won stage defined.");
        }

        return $this->moveToStage($wonStage, $userId);
    }

    /**
     * Mark deal as Lost with reason and optional notes.
     */
    public function markLost(?string $reason = null, int|string|null $userId = null, ?string $notes = null): self
    {
        /** @var PipelineStage|null $lostStage */
        $lostStage = $this->pipeline->stages()->where('is_closed_lost', true)->first();

        if ($lostStage === null) {
            throw new RuntimeException("Pipeline [{$this->pipeline->name}] has no closed lost stage defined.");
        }

        return $this->moveToStage($lostStage, $userId, $reason, $notes);
    }

    /**
     * Scope query to deals with open status.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Open->value);
    }

    /**
     * Scope query to deals with won status.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Won->value);
    }

    /**
     * Scope query to deals with lost status.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Lost->value);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DealFactory
    {
        return DealFactory::new();
    }
}
