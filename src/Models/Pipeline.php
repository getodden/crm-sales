<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Database\Factories\PipelineFactory;
use Odden\Sales\Exceptions\PipelineInUseException;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, PipelineStage> $stages
 */
class Pipeline extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'is_default',
        'is_active',
        'team_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.pipelines', 'odden_pipelines');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        // Deleting the pipeline deletes its stages; the database refuses that too, this gives a readable reason.
        static::deleting(function (self $pipeline): void {
            if ($pipeline->isInUse()) {
                throw PipelineInUseException::pipeline();
            }
        });
    }

    /**
     * Whether any deal (trashed ones included) is in this pipeline or any stage history leads into one of its stages.
     */
    public function isInUse(): bool
    {
        return Deal::query()->withTrashed()->where('pipeline_id', $this->id)->exists()
            || DealStageHistory::query()->whereIn('to_stage_id', PipelineStage::query()->where('pipeline_id', $this->id)->select('id'))->exists();
    }

    /**
     * The ordered stages of this pipeline.
     *
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id')->orderBy('sort_order');
    }

    /**
     * The deals belonging to this pipeline.
     *
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'pipeline_id');
    }

    /**
     * Get the default stage for new deals entering this pipeline.
     */
    public function defaultStage(): ?PipelineStage
    {
        return $this->stages()->where('is_closed_won', false)->where('is_closed_lost', false)->first();
    }

    /**
     * Scope to the default pipeline.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to active pipelines.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Add a stage to this pipeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addStage(array $attributes): PipelineStage
    {
        $maxSort = (int) $this->stages()->max('sort_order');
        $attributes['sort_order'] ??= $maxSort + 10;

        /** @var PipelineStage $stage */
        $stage = $this->stages()->create($attributes);

        return $stage;
    }

    /**
     * Get revenue forecast and conversion metrics for this pipeline.
     *
     * @return array{
     *     open_value: float,
     *     open_count: int,
     *     weighted_forecast: float,
     *     won_value: float,
     *     won_count: int,
     *     lost_count: int,
     *     win_rate: float,
     *     average_deal_size: float,
     *     lost_reasons: array<string, int>,
     *     stale_deals_count: int
     * }
     */
    public function forecast(?int $teamId = null): array
    {
        return app(CalculatePipelineForecastAction::class)->execute($this->id, $teamId);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PipelineFactory
    {
        return PipelineFactory::new();
    }
}
