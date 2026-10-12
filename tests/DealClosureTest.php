<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class DealClosureTest extends TestCase
{
    use RefreshDatabase;

    private function dealFor(Contact $contact, Pipeline $pipeline): Deal
    {
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);
        $deal->associateWith($contact, 'primary');

        return $deal;
    }

    private function enroll(Contact $contact, string $name): SalesSequenceEnrollment
    {
        $sequence = SalesSequence::create([
            'name' => $name,
            'steps' => [['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Touch 1']],
            'is_active' => true,
        ]);

        return app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);
    }

    public function test_a_won_deal_does_not_keep_a_lost_reason_passed_with_the_move(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

        $deal->moveToStage($pipeline->stages->firstWhere('is_closed_won', true), lostReason: 'price', lostNotes: 'Too expensive');

        $deal->refresh();
        $this->assertNull($deal->lost_reason);
        $this->assertNull($deal->lost_notes);
    }

    public function test_closing_a_deal_keeps_cadences_of_contacts_with_another_open_deal(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
        $closing = $this->dealFor($contact, $pipeline);
        $this->dealFor($contact, $pipeline);
        $enrollment = $this->enroll($contact, 'Outbound');

        $closing->markLost('price');

        $this->assertSame('active', $enrollment->fresh()?->status);
    }

    public function test_closing_a_deal_keeps_cadences_begun_after_the_contact_became_a_customer(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer, 'became_customer_at' => now()->subMonth()]);
        $deal = $this->dealFor($contact, $pipeline);
        $onboarding = $this->enroll($contact, 'Onboarding');

        $deal->markWon();

        $this->assertSame('active', $onboarding->fresh()?->status);
    }

    public function test_closing_a_deal_stops_prospecting_cadences(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
        $deal = $this->dealFor($contact, $pipeline);
        $outbound = $this->enroll($contact, 'Outbound');

        $deal->markWon();

        $this->assertSame('unenrolled', $outbound->fresh()?->status);
    }
}
