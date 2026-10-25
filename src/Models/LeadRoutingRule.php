<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Sales\Enums\LeadRoutingStrategy;

/**
 * @property int $id
 * @property string $name
 * @property LeadRoutingStrategy $strategy
 * @property array<string, mixed>|null $criteria
 * @property list<int> $assigned_user_ids
 * @property int $last_assigned_index
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LeadRoutingRule extends Model
{
    /**
     * The criteria keys each kind of lead can be matched on. A criterion a lead's kind doesn't have never matches.
     */
    public const CONTACT_CRITERIA = ['lead_status', 'timezone', 'city'];

    public const DEAL_CRITERIA = ['pipeline_id', 'min_amount'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'strategy',
        'criteria',
        'assigned_user_ids',
        'last_assigned_index',
        'is_active',
        'sort_order',
    ];

    /**
     * Default attribute values. -1 means "nobody assigned yet", so round robin starts at the first user.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'last_assigned_index' => -1,
    ];

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            $unsupported = self::unsupportedCriteria($rule->criteria ?? []);

            if ($unsupported !== []) {
                throw new InvalidArgumentException('Lead routing rules cannot match on: '.implode(', ', $unsupported).'. Supported criteria: '.implode(', ', [...self::CONTACT_CRITERIA, ...self::DEAL_CRITERIA]).'.');
            }
        });
    }

    /**
     * The keys in $criteria that no kind of lead can be matched on.
     *
     * @param  array<array-key, mixed>  $criteria
     * @return list<string>
     */
    public static function unsupportedCriteria(array $criteria): array
    {
        return array_values(array_diff(array_map('strval', array_keys($criteria)), [...self::CONTACT_CRITERIA, ...self::DEAL_CRITERIA]));
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => LeadRoutingStrategy::class,
            'criteria' => EncryptedJson::class.':lead_routing_rules.criteria',
            'assigned_user_ids' => 'array',
            'last_assigned_index' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
