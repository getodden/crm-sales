<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Actions\ChangeDealStageAction;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Actions\GenerateQuoteFromDealAction;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Actions\SyncDealAmountAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Enums\QuotaPeriod;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Enums\StageAutomationActionType;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\StageAutomation;
use Odden\Sales\Services\MeetingAvailability;
use Odden\Sales\Tests\Fixtures\User;

class SalesHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_require_active_quote_stage_guard(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        /** @var PipelineStage $initialStage */
        $initialStage = $pipeline->stages->first();
        /** @var PipelineStage $proposalStage */
        $proposalStage = $pipeline->stages->skip(1)->first();

        StageAutomation::create([
            'name' => 'Require Active Quote Guard',
            'stage_id' => $proposalStage->id,
            'action_type' => StageAutomationActionType::RequireActiveQuote,
            'is_active' => true,
        ]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $initialStage->id,
            'status' => DealStatus::Open,
        ]);

        // Attempting to move without quote throws StageRequirementException
        $this->expectException(StageRequirementException::class);
        $deal->moveToStage($proposalStage);
    }

    public function test_require_active_quote_passes_when_quote_exists(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        /** @var PipelineStage $initialStage */
        $initialStage = $pipeline->stages->first();
        /** @var PipelineStage $proposalStage */
        $proposalStage = $pipeline->stages->skip(1)->first();

        StageAutomation::create([
            'name' => 'Require Active Quote Guard',
            'stage_id' => $proposalStage->id,
            'action_type' => StageAutomationActionType::RequireActiveQuote,
            'is_active' => true,
        ]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $initialStage->id,
            'status' => DealStatus::Open,
        ]);

        Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'total_amount' => 5000.00,
        ]);

        $deal->moveToStage($proposalStage);
        $this->assertSame($proposalStage->id, $deal->refresh()->stage_id);
    }

    public function test_require_accepted_quote_stage_guard(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        /** @var PipelineStage $initialStage */
        $initialStage = $pipeline->stages->first();
        /** @var PipelineStage $wonStage */
        $wonStage = $pipeline->stages->firstWhere('is_closed_won', true);

        StageAutomation::create([
            'name' => 'Require Accepted Quote Guard',
            'stage_id' => $wonStage->id,
            'action_type' => StageAutomationActionType::RequireAcceptedQuote,
            'is_active' => true,
        ]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $initialStage->id,
            'status' => DealStatus::Open,
        ]);

        Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent, // Not accepted yet
        ]);

        $this->expectException(StageRequirementException::class);
        $deal->moveToStage($wonStage);
    }

    public function test_deal_won_or_lost_unenrolls_associated_contacts_from_sequences(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        /** @var PipelineStage $initialStage */
        $initialStage = $pipeline->stages->first();
        /** @var PipelineStage $wonStage */
        $wonStage = $pipeline->stages->firstWhere('is_closed_won', true);

        $contact = Contact::factory()->create(['email' => 'prospect@enterprise.com', 'lifecycle_stage' => LifecycleStage::Lead]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $initialStage->id,
            'status' => DealStatus::Open,
        ]);
        $deal->contacts()->attach($contact->id, [
            'parent_type' => $deal->getMorphClass(),
            'child_type' => $contact->getMorphClass(),
        ]);

        $sequence = SalesSequence::create([
            'name' => 'Cold Outbound Q3',
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Touch 1'],
            ],
            'is_active' => true,
        ]);

        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        $this->assertSame('active', $enrollment->status);

        // Move deal to Won
        app(ChangeDealStageAction::class)->execute($deal, $wonStage);

        $enrollment->refresh();
        $this->assertSame('unenrolled', $enrollment->status);
        $this->assertNull($enrollment->next_step_due_at);
    }

    public function test_closing_a_deal_unenrolls_contacts_when_the_contacts_table_is_renamed(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        /** @var PipelineStage $wonStage */
        $wonStage = $pipeline->stages->firstWhere('is_closed_won', true);

        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
        ]);
        $deal->contacts()->attach($contact->id, [
            'parent_type' => $deal->getMorphClass(),
            'child_type' => $contact->getMorphClass(),
        ]);

        $sequence = SalesSequence::create([
            'name' => 'Renamed Table Cadence',
            'steps' => [['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Touch 1']],
            'is_active' => true,
        ]);
        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);

        Schema::rename('odden_contacts', 'renamed_contacts');
        config(['odden-core.tables.contacts' => 'renamed_contacts']);

        app(ChangeDealStageAction::class)->execute($deal, $wonStage);

        $this->assertSame('unenrolled', $enrollment->fresh()->status);
    }

    public function test_booking_meeting_unenrolls_contact_from_active_sequences(): void
    {
        $contact = Contact::factory()->create(['email' => 'busy-exec@acme.org']);
        $sequence = SalesSequence::create([
            'name' => 'Demo Outreach Cadence',
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Initial Touch'],
            ],
            'is_active' => true,
        ]);

        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        $this->assertSame('active', $enrollment->status);

        $user = User::factory()->create();

        $link = SalesMeetingLink::create([
            'slug' => 'alex-demo',
            'title' => 'Product Demo 30m',
            'duration_minutes' => 30,
            // Open every day so "tomorrow" is bookable whatever weekday the suite runs on.
            'working_hours' => array_fill_keys(MeetingAvailability::DAYS, ['09:00-17:00']),
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        app(BookMeetingAction::class)->execute(
            link: $link,
            fullName: 'Alex Vance',
            email: 'busy-exec@acme.org',
            scheduledAt: Carbon::tomorrow()->setHour(14)
        );

        $enrollment->refresh();
        $this->assertSame('unenrolled', $enrollment->status);
        $this->assertNull($enrollment->next_step_due_at);
    }

    public function test_manual_sequence_step_advances_when_activity_is_completed(): void
    {
        $contact = Contact::factory()->create(['email' => 'target@prospect.io']);
        $sequence = SalesSequence::create([
            'name' => 'High-Touch Executive Cadence',
            'steps' => [
                ['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Cold Call'],
                ['step' => 2, 'type' => 'email', 'delay_days' => 2, 'title' => 'Follow up'],
            ],
            'is_active' => true,
        ]);

        $enrollment = app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
        $this->assertSame(1, $enrollment->current_step);

        // 1st Run: Should create a pending Call activity
        $action = new ProcessCadencesAction;
        $stats1 = $action->execute();

        $this->assertSame(1, $stats1['tasks_created']);
        $this->assertSame(1, $enrollment->refresh()->current_step);

        /** @var Activity $callActivity */
        $callActivity = Activity::query()
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->id)
            ->where('type', ActivityType::Call)
            ->first();

        $this->assertNotNull($callActivity);
        $this->assertSame(ActivityStatus::Pending, $callActivity->status);

        // 2nd Run while still Pending: Should NOT advance or duplicate activity
        $stats2 = $action->execute();
        $this->assertSame(0, $stats2['tasks_created']);
        $this->assertSame(1, $enrollment->refresh()->current_step);

        // Mark the activity as completed by the rep
        $callActivity->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        // 3rd Run after completion: Should advance the enrollment to step 2!
        $action->execute();
        $this->assertSame(2, $enrollment->refresh()->current_step);
    }

    public function test_expire_stale_quotes_command(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
        ]);

        // 1. Expired quote (Sent, expired 2 days ago)
        $q1 = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'expires_at' => now()->subDays(2),
        ]);

        // 2. Expired quote (Draft, expired yesterday)
        $q2 = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Draft,
            'expires_at' => now()->subDay(),
        ]);

        // 3. Active quote (Sent, expires in 10 days)
        $q3 = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'expires_at' => now()->addDays(10),
        ]);

        // 4. Accepted quote (Accepted, expired yesterday) -> should NOT be modified
        $q4 = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Accepted,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('sales:expire-quotes')
            ->expectsOutputToContain('Successfully evaluated and expired 2 stale quote proposal(s).')
            ->assertSuccessful();

        $this->assertSame(QuoteStatus::Expired, $q1->refresh()->status);
        $this->assertSame(QuoteStatus::Expired, $q2->refresh()->status);
        $this->assertSame(QuoteStatus::Sent, $q3->refresh()->status);
        $this->assertSame(QuoteStatus::Accepted, $q4->refresh()->status);
    }

    public function test_quote_acceptance_logs_alert_task_for_deal_owner(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'owner_id' => $user->id,
            'status' => DealStatus::Open,
        ]);

        $quote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'total_amount' => 18500.00,
            'public_token' => 'owner-notify-test-123',
        ]);

        app(AcceptQuoteAction::class)->execute(
            token: $quote->public_token,
            signedByName: 'Robert California',
            signedByEmail: 'robert@dundermifflin.com'
        );

        $task = Activity::query()
            ->where('subject_type', $deal->getMorphClass())
            ->where('subject_id', $deal->id)
            ->where('type', ActivityType::Task)
            ->first();

        $this->assertNotNull($task);
        $this->assertStringContainsString('Deal Closed-Won', $task->title);
        $this->assertStringContainsString('Robert California', (string) $task->body);
        $this->assertSame($user->id, $task->creator_id);
    }

    public function test_contact_sales_sequence_enrollments_relation(): void
    {
        $contact = Contact::factory()->create(['email' => 'sales-rel@prospect.co']);
        $sequence = SalesSequence::create([
            'name' => 'Q4 Enterprise Inbound',
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Intro'],
            ],
            'is_active' => true,
        ]);

        app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);

        $this->assertCount(1, $contact->salesSequenceEnrollments);
        $this->assertSame('Q4 Enterprise Inbound', $contact->salesSequenceEnrollments->first()->sequence->name);
    }

    public function test_quota_weighted_lead_routing_prioritizes_rep_with_largest_attainment_gap(): void
    {
        $repHigh = User::factory()->create(['name' => 'High Performer']);
        $repLow = User::factory()->create(['name' => 'Low Attainment Rep']);

        // High performer has 80% attainment
        SalesQuota::create([
            'user_id' => $repHigh->id,
            'period_type' => QuotaPeriod::Quarterly,
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
            'target_amount' => 100000.00,
        ]);
        Deal::factory()->create([
            'owner_id' => $repHigh->id,
            'status' => DealStatus::Won,
            'amount' => 80000.00,
            'closed_at' => now(),
        ]);

        // Low performer has 20% attainment
        SalesQuota::create([
            'user_id' => $repLow->id,
            'period_type' => QuotaPeriod::Quarterly,
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
            'target_amount' => 100000.00,
        ]);
        Deal::factory()->create([
            'owner_id' => $repLow->id,
            'status' => DealStatus::Won,
            'amount' => 20000.00,
            'closed_at' => now(),
        ]);

        $rule = LeadRoutingRule::create([
            'name' => 'Quota Balancing Inbound',
            'strategy' => LeadRoutingStrategy::QuotaWeighted,
            'assigned_user_ids' => [$repHigh->id, $repLow->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $incomingContact = Contact::factory()->create(['email' => 'hotlead@enterprise.com']);

        $result = app(RouteLeadAction::class)->execute($incomingContact);

        $this->assertNotNull($result);
        $this->assertSame($repLow->id, $result['assigned_user_id']);
        $this->assertSame($repLow->id, $incomingContact->fresh()->owner_id);
    }

    public function test_zero_product_count_resets_deal_amount_to_zero(): void
    {
        $deal = Deal::factory()->create(['amount' => 5000.00]);

        $product = DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Enterprise License',
            'unit_price' => 2500.00,
            'quantity' => 2,
            'discount_percent' => 0.00,
            'total_price' => 5000.00,
        ]);

        $this->assertEquals(5000.00, (float) $deal->fresh()->amount);

        // Delete all products
        $product->forceDelete();
        app(SyncDealAmountAction::class)->execute($deal);

        $this->assertEquals(0.00, (float) $deal->fresh()->amount);
    }

    public function test_quote_portal_logs_a_view_for_a_sent_quote(): void
    {
        $user = User::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => $user->id]);

        /** @var Quote $quote */
        $quote = Quote::create([
            'deal_id' => $deal->id,
            'quote_number' => 'Q-2026-VIEW',
            'title' => 'Proposal for Widget Corp',
            'status' => QuoteStatus::Sent,
            'subtotal' => 12000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 12000.00,
            'currency' => 'USD',
            'public_token' => 'view-test-token-777',
        ]);

        $this->get(route('odden.quotes.show', ['token' => $quote->public_token]))->assertOk();

        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);

        $viewActivity = Activity::query()
            ->where('subject_type', $deal->getMorphClass())
            ->where('subject_id', $deal->id)
            ->where('metadata->event', 'portal_view')
            ->first();

        $this->assertNotNull($viewActivity);
        $this->assertStringContainsString('Proposal Viewed by Customer', $viewActivity->title);
    }

    public function test_quote_portal_does_not_show_or_send_a_draft_quote(): void
    {
        $deal = Deal::factory()->create();

        /** @var Quote $quote */
        $quote = Quote::create([
            'deal_id' => $deal->id,
            'quote_number' => 'Q-2026-DRAFT',
            'title' => 'Unfinished proposal',
            'status' => QuoteStatus::Draft,
            'subtotal' => 100.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 100.00,
            'currency' => 'USD',
            'public_token' => 'draft-test-token-888',
        ]);

        $this->get(route('odden.quotes.show', ['token' => $quote->public_token]))->assertNotFound();

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
        $this->assertSame(0, Activity::query()->where('metadata->event', 'portal_view')->count());
    }

    public function test_sales_playbook_execution_on_deal_logs_note_and_syncs_properties(): void
    {
        $user = User::factory()->create();
        $deal = Deal::factory()->create(['name' => 'Acme Cloud Migration']);

        $playbook = SalesPlaybook::create([
            'name' => 'BANT Qualification',
            'slug' => 'bant-qualification',
            'category' => 'qualification',
            'framework' => 'bant',
            'questions' => [
                ['id' => 'budget', 'label' => 'Approved Budget Range', 'type' => 'text', 'target_property' => 'deal_budget'],
                ['id' => 'timeline', 'label' => 'Decision Timeline', 'type' => 'text', 'target_property' => 'decision_timeline'],
            ],
            'is_active' => true,
        ]);

        app(ExecuteSalesPlaybookAction::class)->execute(
            target: $deal,
            playbook: $playbook,
            answers: [
                'budget' => '$50,000 - $75,000',
                'timeline' => 'End of Q4',
            ],
            userId: $user->id
        );

        $this->assertSame('$50,000 - $75,000', $deal->fresh()->getProperty('deal_budget'));
        $this->assertSame('End of Q4', $deal->fresh()->getProperty('decision_timeline'));

        $note = Activity::query()
            ->where('subject_type', $deal->getMorphClass())
            ->where('subject_id', $deal->id)
            ->where('type', ActivityType::Note)
            ->where('title', 'Playbook: BANT Qualification')
            ->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('Playbook Execution: BANT Qualification', (string) $note->body);
        $this->assertStringContainsString('Approved Budget Range', (string) $note->body);
    }

    public function test_generate_quote_from_deal_action_creates_synced_quote(): void
    {
        $user = User::factory()->create();
        $deal = Deal::factory()->create([
            'name' => 'Globex Expansion',
            'amount' => 15000.00,
            'owner_id' => $user->id,
            'currency' => 'USD',
        ]);

        $quote = app(GenerateQuoteFromDealAction::class)->execute($deal);

        $this->assertInstanceOf(Quote::class, $quote);
        $this->assertSame($deal->id, $quote->deal_id);
        $this->assertEquals(15000.00, (float) $quote->total_amount);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertNotNull($quote->public_token);
        $this->assertNotEmpty($quote->items);
    }
}
