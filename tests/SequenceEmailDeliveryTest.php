<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Mail\SequenceStepMail;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;
use Odden\Sales\Tests\Fixtures\User;

beforeEach(function (): void {
    Mail::fake();

    $this->rep = User::factory()->create(['name' => 'Beth Caldwell', 'email' => 'beth@odden.test']);

    $this->template = SalesEmailTemplate::query()->create([
        'name' => 'Cold Intro',
        'subject' => 'Hello {{ contact.first_name }} & team',
        'body_html' => '<p>Hi {{ contact.full_name }}, this is {{ sender.name }}.</p>',
        'category' => 'prospecting',
    ]);

    $this->makeSequence = function (array $steps): SalesSequence {
        return SalesSequence::query()->create([
            'name' => 'Outbound',
            'is_active' => true,
            'user_id' => $this->rep->id,
            'steps' => $steps,
        ]);
    };

    $this->emailStep = fn (?int $templateId, int $delay = 0, int $step = 1): array => [
        'step' => $step,
        'type' => 'email',
        'delay_days' => $delay,
        'title' => "Email {$step}",
        'template_id' => $templateId,
    ];
});

it('queues the rendered template to the enrolled contact', function (): void {
    $contact = Contact::factory()->create([
        'first_name' => 'Dana',
        'last_name' => '<b>Scully</b>',
        'email' => 'dana@example.com',
    ]);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    $stats = app(ProcessCadencesAction::class)->execute();

    expect($stats['emails_sent'])->toBe(1)
        ->and($stats['emails_skipped'])->toBe(0);

    Mail::assertQueued(SequenceStepMail::class, function (SequenceStepMail $mail): bool {
        return $mail->hasTo('dana@example.com')
            && $mail->subjectLine === 'Hello Dana & team'
            && str_contains($mail->htmlBody, 'Dana &lt;b&gt;Scully&lt;/b&gt;')
            && str_contains($mail->htmlBody, 'this is Beth Caldwell');
    });
    Mail::assertQueuedCount(1);

    /** @var Activity $activity */
    $activity = Activity::query()->where('type', ActivityType::Email)->sole();
    expect($activity->status)->toBe(ActivityStatus::Completed)
        ->and($activity->title)->toBe('Hello Dana & team')
        ->and($activity->metadata)->toMatchArray(['step' => 1, 'template_id' => $this->template->id, 'to' => 'dana@example.com']);
});

it('sequence step mail implements ShouldQueue', function (): void {
    expect(is_subclass_of(SequenceStepMail::class, ShouldQueue::class))->toBeTrue();
});

it('skips contacts without an email and records why', function (): void {
    $contact = Contact::factory()->create(['email' => '']);
    $sequence = ($this->makeSequence)([
        ($this->emailStep)($this->template->id),
        ['step' => 2, 'type' => 'call', 'delay_days' => 1, 'title' => 'Call'],
    ]);
    $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    $stats = app(ProcessCadencesAction::class)->execute();

    Mail::assertNothingQueued();
    expect($stats['emails_sent'])->toBe(0)
        ->and($stats['emails_skipped'])->toBe(1)
        ->and($enrollment->refresh()->current_step)->toBe(2)
        ->and($contact->refresh()->last_contacted_at)->toBeNull();

    /** @var Activity $activity */
    $activity = Activity::query()->where('type', ActivityType::Email)->sole();
    expect($activity->status)->toBe(ActivityStatus::Cancelled)
        ->and($activity->metadata)->toMatchArray(['skipped' => true, 'skip_reason' => 'missing_email', 'step' => 1]);
});

it('skips contacts whose email bounced or was found invalid', function (EmailStatusReason $reason, string $skipReason): void {
    $contact = Contact::factory()->create([
        'email' => 'dana@example.com',
        'email_status' => EmailStatus::Invalid,
        'email_status_reason' => $reason,
    ]);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    $stats = app(ProcessCadencesAction::class)->execute();

    Mail::assertNothingQueued();
    expect($stats['emails_skipped'])->toBe(1)
        ->and(Activity::query()->where('type', ActivityType::Email)->sole()->metadata)
        ->toMatchArray(['skipped' => true, 'skip_reason' => $skipReason]);
})->with([
    'bounced' => [EmailStatusReason::Bounced, 'bounced'],
    'no mail server' => [EmailStatusReason::NoMailServer, 'invalid_email'],
]);

it('skips email steps without a usable template instead of sending placeholder text', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([($this->emailStep)(null)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    $stats = app(ProcessCadencesAction::class)->execute();

    Mail::assertNothingQueued();
    expect($stats['emails_sent'])->toBe(0)->and($stats['emails_skipped'])->toBe(1);
    expect(Activity::query()->where('type', ActivityType::Email)->sole()->metadata)
        ->toMatchArray(['skip_reason' => 'missing_template']);
});

it('does not send the same step twice when the command is re-run', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([
        ($this->emailStep)($this->template->id),
        ($this->emailStep)($this->template->id, delay: 2, step: 2),
    ]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    $this->artisan('sales:process-cadences')->assertSuccessful();
    $this->artisan('sales:process-cadences')->assertSuccessful();
    $second = app(ProcessCadencesAction::class)->execute();

    expect($second['emails_sent'])->toBe(0);
    Mail::assertQueuedCount(1);
    expect(Activity::query()->where('type', ActivityType::Email)->count())->toBe(1);
});

it('does not resend a step another run already claimed', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([
        ($this->emailStep)($this->template->id),
        ($this->emailStep)($this->template->id, delay: 0, step: 2),
    ]);
    $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    // Simulate a concurrent run that already sent step 1 between this run loading and processing it.
    SalesSequenceEnrollment::retrieved(function (SalesSequenceEnrollment $loaded) use ($enrollment): void {
        static $done = false;
        if (! $done && $loaded->is($enrollment)) {
            $done = true;
            SalesSequenceEnrollment::query()->whereKey($enrollment->id)->update(['current_step' => 2]);
        }
    });

    $stats = app(ProcessCadencesAction::class)->execute();

    // Step 1 was claimed elsewhere, so this run sends nothing for it.
    expect($stats['emails_sent'])->toBe(0);
    Mail::assertNothingQueued();
});

it('uses the configured queue, mailer and from address', function (): void {
    config([
        'odden-sales.mail.queue' => 'sales-mail',
        'odden-sales.mail.connection' => 'redis',
        'odden-sales.mail.mailer' => 'postmark',
        'odden-sales.mail.from.address' => 'sales@acme.test',
        'odden-sales.mail.from.name' => 'Acme Sales',
    ]);

    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    app(ProcessCadencesAction::class)->execute();

    Mail::assertQueued(SequenceStepMail::class, function (SequenceStepMail $mail): bool {
        return $mail->queue === 'sales-mail'
            && $mail->connection === 'redis'
            && $mail->mailer === 'postmark'
            && $mail->hasFrom('sales@acme.test', 'Acme Sales')
            && $mail->hasReplyTo('beth@odden.test');
    });
});

it('falls back to the app default from address and can send as the enrollment owner', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    $other = User::factory()->create(['name' => 'Mark Hunter', 'email' => 'mark@odden.test']);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $other->id);

    app(ProcessCadencesAction::class)->execute();

    Mail::assertQueued(SequenceStepMail::class, fn (SequenceStepMail $mail): bool => $mail->fromAddress === null
        && $mail->replyToAddress === 'mark@odden.test');

    config(['odden-sales.mail.sequences.send_as_owner' => true]);
    $second = Contact::factory()->create(['email' => 'fox@example.com']);
    app(EnrollContactInSequenceAction::class)->execute($second, $sequence, $other->id);

    app(ProcessCadencesAction::class)->execute();

    Mail::assertQueued(SequenceStepMail::class, fn (SequenceStepMail $mail): bool => $mail->hasTo('fox@example.com')
        && $mail->hasFrom('mark@odden.test', 'Mark Hunter'));
});

it('reports real sends in the command output', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $noEmail = Contact::factory()->create(['email' => '']);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);
    app(EnrollContactInSequenceAction::class)->execute($noEmail, $sequence, $this->rep->id);

    $this->artisan('sales:process-cadences')
        ->expectsTable(['Metric', 'Count'], [
            ['Enrollments Evaluated', 2],
            ['Emails Queued for Delivery', 1],
            ['Emails Skipped (no address or template)', 1],
            ['Cockpit Tasks / Calls Queued', 0],
            ['Auto-Unenrolled Contacts', 0],
            ['Cadences Completed', 0],
        ])
        ->assertSuccessful();
});

it('delivers the queued step through the real mailer', function (): void {
    Mail::swap(new MailManager(app()));
    $contact = Contact::factory()->create(['first_name' => 'Dana', 'email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    app(ProcessCadencesAction::class)->execute();

    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->toString())->toContain('Subject: Hello Dana & team')
        ->and($messages[0]->toString())->toContain('dana@example.com');
});

it('sends replies to the configured reply-to address instead of the rep', function (): void {
    config(['odden-sales.mail.sequences.reply_to' => 'replies@inbound.example']);
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);
    $sequence = ($this->makeSequence)([($this->emailStep)($this->template->id)]);
    app(EnrollContactInSequenceAction::class)->execute($contact, $sequence, $this->rep->id);

    app(ProcessCadencesAction::class)->execute();

    Mail::assertQueued(SequenceStepMail::class, fn (SequenceStepMail $mail): bool => $mail->replyToAddress === 'replies@inbound.example');
});
