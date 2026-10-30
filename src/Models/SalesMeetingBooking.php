<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Support\DispatchesAfterCommit;

/**
 * A slot booked on a meeting link. Active (not cancelled) bookings block the host's calendar.
 *
 * @property int $id
 * @property string $uid Stable key of the booking: the .ics event UID, and what an external calendar should key on.
 * @property int $meeting_link_id
 * @property int $user_id
 * @property int|null $contact_id
 * @property int|null $activity_id
 * @property string $invitee_name
 * @property string $invitee_email
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $timezone
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read SalesMeetingLink $meetingLink
 * @property-read Model $user
 * @property-read Contact|null $contact
 * @property-read Activity|null $activity
 */
class SalesMeetingBooking extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uid',
        'meeting_link_id',
        'user_id',
        'contact_id',
        'activity_id',
        'invitee_name',
        'invitee_email',
        'starts_at',
        'ends_at',
        'timezone',
        'cancelled_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.meeting_bookings', 'odden_sales_meeting_bookings');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invitee_name' => EncryptedString::class.':meeting_bookings.invitee_name',
            'invitee_email' => EncryptedString::class.':meeting_bookings.invitee_email',
        ];
    }

    /**
     * @return BelongsTo<SalesMeetingLink, $this>
     */
    public function meetingLink(): BelongsTo
    {
        return $this->belongsTo(SalesMeetingLink::class, 'meeting_link_id');
    }

    /**
     * The host (the rep who owns the meeting link).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::className(), 'user_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    /**
     * Bookings that still hold their slot.
     *
     * @param  Builder<SalesMeetingBooking>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /**
     * Active bookings that have not ended yet, the ones in progress included.
     *
     * @param  Builder<SalesMeetingBooking>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('ends_at', '>', now());
    }

    /**
     * Free the slot and fire MeetingBookingCancelled after commit. Cancelling a cancelled booking
     * changes nothing and fires nothing. The meeting activity is left for the rep to update.
     *
     * The update only matches a row that is still active, so when two requests cancel the same booking at
     * once only the one that changed the row fires; the other takes the stored cancellation time.
     *
     * $actorName and $reason are passed to the event for the cancellation emails; they are not stored.
     */
    public function cancel(MeetingCancelledBy $by = MeetingCancelledBy::Staff, ?string $actorName = null, ?string $reason = null): self
    {
        if ($this->cancelled_at !== null) {
            return $this;
        }

        $now = $this->freshTimestamp();
        $changed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => $now, self::UPDATED_AT => $now]);

        if ($changed === 0) {
            $this->forceFill(['cancelled_at' => static::query()->whereKey($this->getKey())->value('cancelled_at')])
                ->syncOriginalAttribute('cancelled_at');

            return $this;
        }

        $this->forceFill(['cancelled_at' => $now, self::UPDATED_AT => $now])
            ->syncOriginalAttributes(['cancelled_at', self::UPDATED_AT]);

        // Loaded now, inside the caller's transaction: the listener runs after commit, when the link may already be deleted.
        $this->loadMissing(['meetingLink', 'user']);

        DispatchesAfterCommit::dispatch(new MeetingBookingCancelled($this, $this->uid, $by, $actorName, $reason));

        return $this;
    }
}
