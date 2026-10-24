<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Encryption\Casts\EncryptedAmount;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\QuotaPeriod;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $pipeline_id
 * @property QuotaPeriod $period_type
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property float $target_amount
 * @property string $currency
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Pipeline|null $pipeline
 */
class SalesQuota extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'pipeline_id',
        'period_type',
        'period_start',
        'period_end',
        'target_amount',
        'currency',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.quotas', 'odden_sales_quotas');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_type' => QuotaPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'target_amount' => EncryptedAmount::class.':sales_quotas.target_amount',
        ];
    }

    /**
     * The sales representative who owns this quota target.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * The pipeline this quota applies to (or null for all pipelines).
     *
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }
}
