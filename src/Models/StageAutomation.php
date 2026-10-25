<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Sales\Enums\StageAutomationActionType;

/**
 * @property int $id
 * @property int $stage_id
 * @property string $name
 * @property string $event_trigger
 * @property StageAutomationActionType $action_type
 * @property array<string, mixed>|null $action_payload
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read PipelineStage $stage
 */
class StageAutomation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'stage_id',
        'name',
        'event_trigger',
        'action_type',
        'action_payload',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.automations', 'odden_stage_automations');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => StageAutomationActionType::class,
            'action_payload' => EncryptedJson::class.':stage_automations.action_payload',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The pipeline stage this automation rule is bound to.
     *
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }
}
