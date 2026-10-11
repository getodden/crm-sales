<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Actions\StopSequencesOnReplyAction;
use Odden\Sales\Mail\SequenceStepMail;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

beforeEach(function (): void {
    Mail::fake();

    $this->template = SalesEmailTemplate::query()->create(['name' => 'Intro', 'subject' => 'Hello', 'body_html' => '<p>Hi</p>', 'category' => 'prospecting']);

    $this->sequence = function (string $name = 'Outbound'): SalesSequence {
        return SalesSequence::query()->create([
            'name' => $name,
            'is_active' => true,
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'One', 'template_id' => $this->template->id],
                ['step' => 2, 'type' => 'email', 'delay_days' => 0, 'title' => 'Two', 'template_id' => $this->template->id],
            ],
        ]);
    };
});

it('unenrolls the contact from every active sequence, logs it, and stops later steps', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $enroll = app(EnrollContactInSequenceAction::class);
    $first = $enroll->execute($contact, ($this->sequence)('Outbound'));
    $second = $enroll->execute($contact, ($this->sequence)('Nurture'));

    app(ProcessCadencesAction::class)->execute();
    Mail::assertQueued(SequenceStepMail::class, 2);

    expect(app(StopSequencesOnReplyAction::class)->execute($contact))->toBe(2)
        ->and($first->fresh()->status)->toBe('unenrolled')->and($first->fresh()->next_step_due_at)->toBeNull()
        ->and($second->fresh()->status)->toBe('unenrolled');

    app(ProcessCadencesAction::class)->execute();
    Mail::assertQueued(SequenceStepMail::class, 2); // nothing new after the stop

    $stops = Activity::query()->where('title', 'like', 'Sequence stopped:%')->get();
    expect($stops)->toHaveCount(2)->and($stops->pluck('title')->all())->toContain('Sequence stopped: Outbound', 'Sequence stopped: Nurture');
});

it('leaves other contacts, and enrollments that already finished, alone', function (): void {
    $dana = Contact::factory()->create(['email' => 'dana@example.com']);
    $fox = Contact::factory()->create(['email' => 'fox@example.com']);
    $sequence = ($this->sequence)();
    $enroll = app(EnrollContactInSequenceAction::class);
    $enroll->execute($fox, $sequence);
    $done = $enroll->execute($dana, $sequence);
    $done->update(['status' => 'completed']);

    expect(app(StopSequencesOnReplyAction::class)->execute($dana))->toBe(0)
        ->and(SalesSequenceEnrollment::query()->where('contact_id', $fox->id)->value('status'))->toBe('active')
        ->and($done->fresh()->status)->toBe('completed');
});
