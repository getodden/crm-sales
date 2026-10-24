<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesSequence;

class ProcessCadencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_process_due_cadences_and_dispatch_emails(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Gordon',
            'last_name' => 'Freeman',
            'email' => 'gordon@blackmesa.internal',
            'lead_status' => LeadStatus::New,
        ]);

        $template = SalesEmailTemplate::query()->create([
            'name' => 'Cold Intro',
            'subject' => 'Hello {{ contact.first_name }}',
            'body_html' => '<p>Excited to connect with {{ contact.full_name }}</p>',
            'category' => 'prospecting',
        ]);

        $sequence = SalesSequence::query()->create([
            'name' => 'Inbound Fast Response',
            'is_active' => true,
            'steps' => [
                [
                    'step' => 1,
                    'type' => 'email',
                    'delay_days' => 0,
                    'title' => 'Initial Intro Email',
                    'template_id' => $template->id,
                ],
                [
                    'step' => 2,
                    'type' => 'call',
                    'delay_days' => 1,
                    'title' => 'Follow-up Call',
                ],
            ],
        ]);

        $enrollAction = app(EnrollContactInSequenceAction::class);
        $enrollment = $enrollAction->execute($contact, $sequence);

        $action = new ProcessCadencesAction;
        $stats = $action->execute();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['emails_sent']);

        $enrollment->refresh();
        $this->assertSame(2, $enrollment->current_step);

        $contact->refresh();
        $this->assertSame(LeadStatus::InProgress, $contact->lead_status);
        $this->assertNotNull($contact->last_contacted_at);

        $this->assertTrue(Activity::query()->where([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => ActivityType::Email->value,
        ])->get()->contains(fn (Activity $row): bool => $row->title == 'Hello Gordon'));
    }

    public function test_process_cadences_command_runs_successfully(): void
    {
        $this->artisan('sales:process-cadences')
            ->assertSuccessful();
    }

    private function callSequence(): SalesSequence
    {
        return SalesSequence::query()->create([
            'name' => 'Call cadence',
            'is_active' => true,
            'steps' => [
                ['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Intro call'],
                ['step' => 2, 'type' => 'call', 'delay_days' => 1, 'title' => 'Follow-up call'],
            ],
        ]);
    }

    public function test_a_deleted_contact_is_unenrolled_and_does_not_stop_the_run(): void
    {
        $sequence = $this->callSequence();
        $gone = Contact::factory()->create(['email' => 'gone@example.com', 'lead_status' => LeadStatus::New]);
        $stays = Contact::factory()->create(['email' => 'stays@example.com', 'lead_status' => LeadStatus::New]);

        $goneEnrollment = app(EnrollContactInSequenceAction::class)->execute($gone, $sequence);
        app(EnrollContactInSequenceAction::class)->execute($stays, $sequence);
        $gone->delete();

        $stats = (new ProcessCadencesAction)->execute();

        $this->assertSame(1, $stats['unenrolled']);
        $this->assertSame(1, $stats['tasks_created'], 'The enrollment after the deleted contact still ran');
        $this->assertSame('unenrolled', $goneEnrollment->fresh()?->status);
    }

    public function test_a_contact_enrolled_again_does_not_inherit_the_steps_of_an_earlier_run(): void
    {
        $sequence = $this->callSequence();
        $contact = Contact::factory()->create(['email' => 'again@example.com', 'lead_status' => LeadStatus::New]);

        $first = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        (new ProcessCadencesAction)->execute();
        Activity::query()->where('subject_id', $contact->id)->update(['status' => ActivityStatus::Completed]);
        $first->update(['status' => 'unenrolled', 'next_step_due_at' => null]);
        $this->travel(1)->day();

        $second = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        $this->assertSame($first->id, $second->id, 'Enrolling again re-uses the row');

        $stats = (new ProcessCadencesAction)->execute();

        $this->assertSame(1, $stats['tasks_created'], 'The new run gets its own step 1 task');
        $this->assertSame(1, $second?->fresh()?->current_step, 'It waits for the rep instead of skipping ahead');
    }

    public function test_a_contact_who_became_a_customer_during_the_cadence_leaves_it(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $this->callSequence());

        $this->travel(1)->hours();
        $contact->update(['lifecycle_stage' => LifecycleStage::Customer, 'became_customer_at' => now()]);

        $stats = app(ProcessCadencesAction::class)->execute();

        $this->assertSame(1, $stats['unenrolled']);
        $this->assertSame('unenrolled', $enrollment->fresh()?->status);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_cadence_begun_after_the_contact_became_a_customer_keeps_going(): void
    {
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer, 'became_customer_at' => now()->subWeek()]);
        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $this->callSequence());

        $stats = app(ProcessCadencesAction::class)->execute();

        $this->assertSame(0, $stats['unenrolled']);
        $this->assertSame(1, $stats['tasks_created']);
        $this->assertSame('active', $enrollment->fresh()?->status);
    }

    public function test_the_run_processes_enrollments_in_chunks(): void
    {
        $sequence = $this->callSequence();
        foreach (Contact::factory()->count(3)->create() as $contact) {
            app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        }

        DB::enableQueryLog();
        $stats = app(ProcessCadencesAction::class)->execute();

        $this->assertSame(3, $stats['tasks_created']);
        $enrollmentSelects = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'odden_sales_sequence_enrollments'));
        $this->assertTrue($enrollmentSelects->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'limit')), 'Enrollments are read a page at a time');
    }
}
