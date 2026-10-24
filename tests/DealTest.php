<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyHistory;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Events\DealLost;
use Odden\Sales\Events\DealMovedStage;
use Odden\Sales\Events\DealWon;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class DealTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_deal_with_custom_properties(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages()->firstOrFail();

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'name' => 'Acme Cloud Migration',
            'amount' => 45000.00,
            'currency' => 'USD',
            'properties' => [
                'deal_source' => 'Inbound Webform',
                'contract_length_months' => 24,
            ],
        ]);

        $this->assertDatabaseHas('odden_deals', [
            'id' => $deal->id,
            'name' => 'Acme Cloud Migration',
            'amount' => 45000.00,
            'status' => 'open',
        ]);

        $this->assertSame('Inbound Webform', $deal->fresh()->properties['deal_source']);
        $this->assertSame(24, $deal->fresh()->properties['contract_length_months']);
    }

    public function test_can_associate_deal_with_contact_and_company(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages()->firstOrFail();

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Satya',
            'last_name' => 'Nadella',
        ]);

        $company = Company::factory()->create([
            'name' => 'Microsoft',
        ]);

        $deal->associateWith($contact, 'primary_contact');
        $deal->associateWith($company, 'primary_company');

        $this->assertTrue($deal->isAssociatedWith($contact));
        $this->assertTrue($deal->isAssociatedWith($company));
        $this->assertCount(1, $deal->contacts);
        $this->assertCount(1, $deal->companies);
        $this->assertSame('Satya', $deal->contacts->first()->first_name);
        $this->assertSame('Microsoft', $deal->companies->first()->name);
    }

    public function test_moving_deal_to_won_stage_updates_status_and_dispatches_events(): void
    {
        Event::fake([DealMovedStage::class, DealWon::class]);

        $pipeline = Pipeline::factory()->withStages()->create();
        $initialStage = $pipeline->stages()->where('code', 'discovery')->firstOrFail();
        $wonStage = $pipeline->stages()->where('is_closed_won', true)->firstOrFail();

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $initialStage->id,
            'status' => DealStatus::Open,
        ]);

        $deal->moveToStage($wonStage);

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame($wonStage->id, $deal->stage_id);

        Event::assertDispatched(DealMovedStage::class);
        Event::assertDispatched(DealWon::class);
    }

    public function test_moving_deal_to_lost_stage_records_lost_reason(): void
    {
        Event::fake([DealMovedStage::class, DealLost::class]);

        $pipeline = Pipeline::factory()->withStages()->create();
        $lostStage = $pipeline->stages()->where('is_closed_lost', true)->firstOrFail();

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'status' => DealStatus::Open,
        ]);

        $deal->moveToStage($lostStage, lostReason: 'Competitor undercut price by 30%');

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame('Competitor undercut price by 30%', $deal->lost_reason);
        $this->assertNotNull($deal->closed_at);

        Event::assertDispatched(DealLost::class);
    }

    public function test_timeline_rolls_up_activities_from_associated_contacts(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        $contact = Contact::factory()->create();
        $deal->associateWith($contact);

        $deal->logNote('Proposal review meeting agreed for next Tuesday');
        $contact->logCall('Inbound inquiry call', 'Customer asked about enterprise SSO and SAML');

        // Timeline on deal with rollups includes deal note AND contact call
        $timeline = $deal->timeline(includeAssociated: true)->get();

        $this->assertCount(2, $timeline);
        $this->assertTrue($timeline->contains('title', 'Note added'));
        $this->assertTrue($timeline->contains('title', 'Inbound inquiry call'));
    }

    public function test_deal_audits_property_and_amount_changes(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'amount' => 10000.00,
        ]);

        $deal->update([
            'amount' => 25000.00,
        ]);

        $this->assertTrue(PropertyHistory::query()->where([
            'auditable_type' => $deal->getMorphClass(),
            'auditable_id' => $deal->id,
            'property_name' => 'amount',
        ])->get()->contains(fn (PropertyHistory $row): bool => $row->old_value == '10000.00'));
    }

    public function test_contact_and_company_can_query_associated_deals(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'name' => 'Acme Global Expansion',
        ]);

        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        $deal->associateWith($contact);
        $deal->associateWith($company);

        // Access dynamic deals relation registered via resolveRelationUsing
        $this->assertCount(1, $contact->getRelationValue('deals'));
        $this->assertSame('Acme Global Expansion', $contact->getRelationValue('deals')->first()->name);

        $this->assertCount(1, $company->getRelationValue('deals'));
        $this->assertSame('Acme Global Expansion', $company->getRelationValue('deals')->first()->name);
    }

    public function test_deal_rotting_detection_and_days_in_stage(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages()->firstOrFail();
        $stage->update(['rot_after_days' => 7]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'created_at' => now()->subDays(10),
        ]);

        $this->assertSame(10, $deal->daysInCurrentStage());
        $this->assertTrue($deal->isRotten());

        // Fast forward moving to a new stage resets days in stage
        $nextStage = $pipeline->stages()->skip(1)->firstOrFail();
        $nextStage->update(['rot_after_days' => 14]);

        $deal->moveToStage($nextStage);

        $this->assertSame(0, $deal->fresh()->daysInCurrentStage());
        $this->assertFalse($deal->fresh()->isRotten());
    }

    public function test_mark_deal_lost_with_structured_reason_and_notes(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        $deal->markLost('competitor', null, 'Opted for alternative vendor due to lower initial setup cost.');

        $this->assertSame(DealStatus::Lost, $deal->fresh()->status);
        $this->assertSame('competitor', $deal->fresh()->lost_reason);
        $this->assertSame('Opted for alternative vendor due to lower initial setup cost.', $deal->fresh()->lost_notes);
        $this->assertNotNull($deal->fresh()->closed_at);
    }

    public function test_deal_created_without_status_defaults_to_open_in_memory(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        $deal = Deal::create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'name' => 'No Explicit Status',
            'amount' => 1000.00,
        ]);

        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertFalse($deal->isRotten());
        $this->assertIsArray($deal->getHealthScore());
    }
}
