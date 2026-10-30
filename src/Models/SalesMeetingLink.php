<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Support\UserModel;
use Odden\Sales\Exceptions\MeetingLinkInUseException;

/**
 * @property int $id
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property string|null $description
 * @property array<string, list<string>>|null $working_hours Day name => list of "HH:MM-HH:MM" windows.
 * @property string|null $timezone
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model $user
 * @property-read Collection<int, SalesMeetingBooking> $bookings
 */
class SalesMeetingLink extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'title',
        'duration_minutes',
        'buffer_minutes',
        'description',
        'working_hours',
        'timezone',
        'is_active',
    ];

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        // Deleting the link deletes its bookings without cancelling them; upcoming ones must be cancelled or pass first.
        static::deleting(function (self $link): void {
            $upcoming = $link->upcomingBookingsCount();

            if ($upcoming > 0) {
                throw new MeetingLinkInUseException($upcoming);
            }
        });
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.meeting_links', 'odden_sales_meeting_links');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'working_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The sales representative who owns this scheduler link.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Bookings made through this link.
     *
     * @return HasMany<SalesMeetingBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(SalesMeetingBooking::class, 'meeting_link_id');
    }

    /**
     * Bookings that are not cancelled and have not ended yet. They block deleting the link.
     */
    public function upcomingBookingsCount(): int
    {
        return $this->bookings()->upcoming()->count();
    }

    /**
     * The timezone working hours are interpreted in: the link's own, or the app's.
     */
    public function timezoneName(): string
    {
        $timezone = $this->timezone;

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
