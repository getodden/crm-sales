<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property int $sequence_id
 * @property int $contact_id
 * @property int $current_step
 * @property string $status
 * @property CarbonInterface|null $next_step_due_at
 * @property int|null $enrolled_by_id
 * @property CarbonInterface|null $enrolled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read SalesSequence $sequence
 * @property-read Contact $contact
 * @property-read Model|null $enrolledBy
 */
class SalesSequenceEnrollment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sequence_id',
        'contact_id',
        'current_step',
        'status',
        'next_step_due_at',
        'enrolled_by_id',
        'enrolled_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.sequence_enrollments', 'odden_sales_sequence_enrollments');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'next_step_due_at' => 'date',
            'enrolled_at' => 'datetime',
        ];
    }

    /**
     * The sequence this enrollment belongs to.
     *
     * @return BelongsTo<SalesSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(SalesSequence::class, 'sequence_id');
    }

    /**
     * The contact enrolled in this sequence.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * The user who enrolled the contact (the sequence's "owner" for this contact).
     *
     * @return BelongsTo<Model, $this>
     */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::className(), 'enrolled_by_id');
    }

    /**
     * Advance to the next sequence step or complete if on final step.
     */
    public function advanceStep(): self
    {
        $this->update($this->nextStepAttributes());

        return $this;
    }

    /**
     * Atomically advance past $step, but only if this enrollment is still active and on that step.
     *
     * Returns false when another process has already moved it on (or unenrolled it), so callers can
     * run a step's side effects exactly once.
     */
    public function claimStep(int $step): bool
    {
        $attributes = $this->nextStepAttributes($step);
        $updatedAt = $this->getUpdatedAtColumn();
        if ($updatedAt !== null) {
            $attributes[$updatedAt] = $this->freshTimestampString();
        }

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', 'active')
            ->where('current_step', $step)
            ->update($attributes) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * @return array{status?: string, current_step?: int, next_step_due_at: string|null}
     */
    protected function nextStepAttributes(?int $fromStep = null): array
    {
        $fromStep ??= $this->current_step;
        $totalSteps = $this->sequence->totalSteps();

        if ($fromStep >= $totalSteps) {
            return [
                'status' => 'completed',
                'next_step_due_at' => null,
            ];
        }

        $steps = $this->sequence->steps ?? [];
        $nextStepDef = $steps[$fromStep] ?? null; // 0-based index of the next step
        $delayDays = $nextStepDef !== null ? $nextStepDef['delay_days'] : 1;

        return [
            'current_step' => $fromStep + 1,
            'next_step_due_at' => now()->addDays($delayDays)->toDateString(),
        ];
    }
}
