<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Sales\Mail\SalesMail;
use Odden\Sales\Mail\SequenceStepMail;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesSequenceEnrollment;

class ProcessCadencesAction
{
    /**
     * Why an email step was not sent, keyed by the skip_reason stored in the activity metadata.
     */
    public const SKIP_REASONS = [
        'missing_email' => 'Not sent: the contact has no email address.',
        'invalid_email' => 'Not sent: the contact\'s email address is not valid.',
        'missing_template' => 'Not sent: the step has no email template, or its template was deleted.',
    ];

    /**
     * Process due steps across all active sales sequence enrollments.
     *
     * @return array{
     *     processed: int,
     *     emails_sent: int,
     *     emails_skipped: int,
     *     tasks_created: int,
     *     unenrolled: int,
     *     completed: int
     * }
     */
    public function execute(): array
    {
        $todayStr = Carbon::today()->toDateString();

        $stats = [
            'processed' => 0,
            'emails_sent' => 0,
            'emails_skipped' => 0,
            'tasks_created' => 0,
            'unenrolled' => 0,
            'completed' => 0,
        ];

        /** @var Collection<int, SalesSequenceEnrollment> $dueEnrollments */
        $dueEnrollments = SalesSequenceEnrollment::query()
            ->where('status', 'active')
            ->where(function ($q) use ($todayStr): void {
                $q->whereNull('next_step_due_at')
                    ->orWhereDate('next_step_due_at', '<=', $todayStr);
            })
            ->with(['contact.companies', 'sequence.user', 'enrolledBy'])
            ->get();

        foreach ($dueEnrollments as $enrollment) {
            $contact = $enrollment->contact;
            $sequence = $enrollment->sequence;

            // The contact was deleted (soft-deleted contacts do not load): there is nobody to run the cadence for,
            // and one such enrollment must not stop every enrollment after it.
            if ($enrollment->getRelation('contact') === null) {
                $enrollment->update(['status' => 'unenrolled', 'next_step_due_at' => null]);
                $stats['unenrolled']++;

                continue;
            }

            if ($enrollment->getRelation('sequence') === null || ! $sequence->is_active) {
                continue;
            }

            $stats['processed']++;

            // Auto-exit if contact has already become a Customer or has bad timing/unqualified
            if ($contact->lead_status === LeadStatus::Unqualified || $contact->lead_status === LeadStatus::BadTiming) {
                $enrollment->update([
                    'status' => 'unenrolled',
                    'next_step_due_at' => null,
                ]);
                $stats['unenrolled']++;

                continue;
            }

            $steps = $sequence->steps ?? [];
            $stepIndex = $enrollment->current_step - 1;
            $stepDef = $steps[$stepIndex] ?? null;

            if ($stepDef === null) {
                $enrollment->update([
                    'status' => 'completed',
                    'next_step_due_at' => null,
                ]);
                $stats['completed']++;

                continue;
            }

            $stepType = $stepDef['type'];
            $stepTitle = $stepDef['title'];

            if ($stepType === 'email') {
                $result = $this->runEmailStep($enrollment, $stepDef);

                if ($result === 'sent') {
                    $stats['emails_sent']++;
                } elseif ($result === 'skipped') {
                    $stats['emails_skipped']++;
                }
            } else {
                // Manual action: Call, LinkedIn, or Task
                $actType = match ($stepType) {
                    'call' => ActivityType::Call,
                    'linkedin' => ActivityType::LinkedIn,
                    default => ActivityType::Task,
                };

                // Check if existing activity was already created for this enrollment step
                $existingActivity = $this->activityForStep($enrollment, $contact, $sequence->name, $actType);

                if ($existingActivity !== null) {
                    if ($existingActivity->status === ActivityStatus::Completed) {
                        // Rep finished manual step - advance sequence to next step
                        if ($enrollment->claimStep($enrollment->current_step)) {
                            $contact->markContacted();
                        }
                    }
                    // If still pending, wait for rep completion
                } else {
                    $contact->logActivity(
                        type: $actType,
                        title: "{$actType->label()}: {$stepTitle}",
                        body: "Cadence [{$sequence->name}] Step {$enrollment->current_step}",
                        metadata: [
                            'sequence_enrollment_id' => $enrollment->id,
                            'step' => $enrollment->current_step,
                        ],
                        status: ActivityStatus::Pending,
                        dueAt: now(),
                        creatorId: $enrollment->enrolled_by_id
                    );
                    $stats['tasks_created']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Send (queue) one email step, or skip it when it can't be sent. Either way the enrollment moves on.
     *
     * Advancing the enrollment is the claim: it only succeeds if the enrollment is still on this step,
     * so a step is never sent twice even if two runs overlap. The mail is queued after the
     * transaction commits.
     *
     * @param  array{step: int, type: string, delay_days: int, title: string, template_id?: int|null}  $stepDef
     * @return 'sent'|'skipped'|null Null when another run already handled the step.
     */
    protected function runEmailStep(SalesSequenceEnrollment $enrollment, array $stepDef): ?string
    {
        $contact = $enrollment->contact;
        $sequence = $enrollment->sequence;
        $step = $enrollment->current_step;
        $owner = $enrollment->enrolledBy ?? $sequence->user;

        $email = trim((string) $contact->email);
        $templateId = $stepDef['template_id'] ?? null;
        /** @var SalesEmailTemplate|null $template */
        $template = $templateId !== null ? SalesEmailTemplate::query()->find($templateId) : null;

        $skipReason = match (true) {
            $email === '' => 'missing_email',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'invalid_email',
            $template === null => 'missing_template',
            default => null,
        };

        $metadata = [
            'sequence_id' => $sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'step' => $step,
            'template_id' => $templateId,
        ];

        return DB::transaction(
            fn (): ?string => $this->claimAndRunEmailStep($enrollment, $stepDef, $email, $template, $skipReason, $metadata)
        );
    }

    /**
     * Runs inside the step transaction: claim the step, then either log the skip or log and queue the email.
     *
     * @param  array{step: int, type: string, delay_days: int, title: string, template_id?: int|null}  $stepDef
     * @param  array<string, mixed>  $metadata
     * @return 'sent'|'skipped'|null Null when another run already handled the step.
     */
    protected function claimAndRunEmailStep(
        SalesSequenceEnrollment $enrollment,
        array $stepDef,
        string $email,
        ?SalesEmailTemplate $template,
        ?string $skipReason,
        array $metadata
    ): ?string {
        $contact = $enrollment->contact;
        $sequence = $enrollment->sequence;
        $step = $enrollment->current_step;
        $owner = $enrollment->enrolledBy ?? $sequence->user;

        if (! $enrollment->claimStep($step)) {
            return null;
        }

        if ($skipReason !== null || $template === null) {
            $contact->logActivity(
                type: ActivityType::Email,
                title: "Not sent: {$stepDef['title']}",
                body: self::SKIP_REASONS[$skipReason ?? 'missing_template']." (Cadence [{$sequence->name}] Step {$step})",
                metadata: $metadata + ['skipped' => true, 'skip_reason' => $skipReason ?? 'missing_template'],
                status: ActivityStatus::Cancelled,
                creatorId: $enrollment->enrolled_by_id
            );

            return 'skipped';
        }

        $rendered = $template->renderWithContext($contact, null, $owner);

        $contact->logActivity(
            type: ActivityType::Email,
            title: $rendered['subject'],
            body: $rendered['body_html'],
            metadata: $metadata + ['to' => $email],
            status: ActivityStatus::Completed,
            creatorId: $enrollment->enrolled_by_id
        );

        $contact->markContacted();

        if ($contact->lead_status === LeadStatus::New) {
            $contact->updateQuietly(['lead_status' => LeadStatus::InProgress]);
        }

        $ownerEmail = $owner?->getAttribute('email');
        $ownerEmail = is_string($ownerEmail) && $ownerEmail !== '' ? $ownerEmail : null;
        $ownerName = $owner !== null ? UserModel::displayName($owner, '') : '';
        $ownerName = $ownerName !== '' ? $ownerName : null;
        $sendAsOwner = (bool) config('odden-sales.mail.sequences.send_as_owner', false) && $ownerEmail !== null;

        SalesMail::to($email, $contact->full_name)->queue(new SequenceStepMail(
            subjectLine: $rendered['subject'],
            htmlBody: $rendered['body_html'],
            fromAddress: $sendAsOwner ? $ownerEmail : null,
            fromName: $sendAsOwner ? $ownerName : null,
            replyToAddress: $ownerEmail,
            replyToName: $ownerName,
        ));

        return 'sent';
    }

    /**
     * The activity this enrollment already created for its current manual step, if any.
     *
     * Activities carry the enrollment id and step in their metadata. Activities from before that metadata existed are
     * recognised by their text. Either way only activities created since the current run began count, so a contact
     * who is enrolled again is not treated as having already done the steps of the earlier run.
     */
    protected function activityForStep(SalesSequenceEnrollment $enrollment, Contact $contact, string $sequenceName, ActivityType $type): ?Activity
    {
        // Only what happened since this run began: the row is re-used when a contact is enrolled again.
        $base = Activity::query()
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->id)
            ->where('type', $type)
            ->where('created_at', '>=', $enrollment->enrolled_at ?? $enrollment->created_at);

        /** @var Activity|null $current */
        $current = (clone $base)
            ->where('metadata->sequence_enrollment_id', $enrollment->id)
            ->where('metadata->step', $enrollment->current_step)
            ->latest('id')
            ->first();

        if ($current !== null) {
            return $current;
        }

        $marker = "Cadence [{$sequenceName}] Step {$enrollment->current_step}";

        /** @var Activity|null $legacy */
        $legacy = (clone $base)
            ->whereNull('metadata->sequence_enrollment_id')
            ->latest('id')
            ->get()
            ->first(fn (Activity $activity): bool => str_contains((string) $activity->body, $marker));

        return $legacy;
    }
}
