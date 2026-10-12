<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Sales\Database\Factories\PipelineStageFactory;
use Odden\Sales\Exceptions\PipelineInUseException;

/**
 * @property int $id
 * @property int $pipeline_id
 * @property string $name
 * @property string $code
 * @property int $probability
 * @property int $sort_order
 * @property bool $is_closed_won
 * @property bool $is_closed_lost
 * @property int|null $rot_after_days
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, StageAutomation> $automations
 */
class PipelineStage extends Model
{
    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pipeline_id',
        'name',
        'code',
        'probability',
        'sort_order',
        'is_closed_won',
        'is_closed_lost',
        'rot_after_days',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.stages', 'odden_pipeline_stages');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'sort_order' => 'integer',
            'is_closed_won' => 'boolean',
            'is_closed_lost' => 'boolean',
            'rot_after_days' => 'integer',
        ];
    }

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        // The database refuses the delete too; this gives a readable reason.
        static::deleting(function (self $stage): void {
            if ($stage->isInUse()) {
                throw PipelineInUseException::stage();
            }
        });
    }

    /**
     * Whether any deal (trashed ones included) is in this stage or any stage history leads into it.
     */
    public function isInUse(): bool
    {
        return Deal::query()->withTrashed()->where('stage_id', $this->id)->exists()
            || DealStageHistory::query()->where('to_stage_id', $this->id)->exists();
    }

    /**
     * The pipeline this stage belongs to.
     *
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * Deals currently in this stage.
     *
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    /**
     * Workflow automation rules configured for this stage.
     *
     * @return HasMany<StageAutomation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(StageAutomation::class, 'stage_id')->orderBy('sort_order');
    }

    /**
     * Check if this stage represents a closed state.
     */
    public function isClosed(): bool
    {
        return $this->is_closed_won || $this->is_closed_lost;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PipelineStageFactory
    {
        return PipelineStageFactory::new();
    }
}
