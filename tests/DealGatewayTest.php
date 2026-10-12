<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\DealOutcome;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Services\EloquentDealGateway;

class DealGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_binds_the_deal_gateway(): void
    {
        $this->assertInstanceOf(EloquentDealGateway::class, app(DealGateway::class));
    }

    public function test_creates_an_open_deal_in_the_first_stage_and_associates_the_contact(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create();

        $snapshot = app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, $pipeline->id);

        $this->assertNotNull($snapshot);
        $this->assertSame(DealOutcome::Open, $snapshot->status);
        $this->assertSame($pipeline->stages()->orderBy('sort_order')->firstOrFail()->id, $snapshot->stageId);
        $this->assertSame(5000.0, $snapshot->amount);
        $this->assertTrue($contact->isAssociatedWith(Deal::query()->findOrFail($snapshot->id), 'primary'));
    }

    public function test_the_deal_reads_its_contact_and_company_back(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        $contact->associateWith($company, 'primary');

        $snapshot = app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, $pipeline->id, associateCompany: true);

        $deal = Deal::query()->findOrFail($snapshot?->id);
        $this->assertSame([$contact->id], $deal->contacts->modelKeys());
        $this->assertSame([$company->id], $deal->companies->modelKeys());
        $this->assertSame([$deal->id], $contact->deals->modelKeys());
    }

    public function test_uses_the_default_active_pipeline_and_its_first_open_stage(): void
    {
        Pipeline::factory()->withStages()->create(['is_active' => false]);
        Pipeline::factory()->withStages()->create();
        $default = Pipeline::factory()->withStages()->default()->create();
        $default->stages()->where('code', 'discovery')->update(['sort_order' => 99]);

        $snapshot = app(DealGateway::class)->createOpenDeal(Contact::factory()->create(), 'Acme', 5000.0);

        $this->assertSame($default->id, $snapshot?->pipelineId);
        $this->assertSame($default->stages()->where('code', 'qualification')->value('id'), $snapshot->stageId);
    }

    public function test_falls_back_to_the_first_active_pipeline_without_a_default(): void
    {
        Pipeline::factory()->withStages()->create(['is_active' => false]);
        $active = Pipeline::factory()->withStages()->create();

        $snapshot = app(DealGateway::class)->createOpenDeal(Contact::factory()->create(), 'Acme', 5000.0);

        $this->assertSame($active->id, $snapshot?->pipelineId);
    }

    public function test_refuses_a_stage_from_another_pipeline(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $other = Pipeline::factory()->withStages()->create();

        $this->assertNull(app(DealGateway::class)->createOpenDeal(Contact::factory()->create(), 'Acme', 5000.0, $pipeline->id, $other->stages->first()->id));
        $this->assertSame(0, Deal::query()->count());
    }

    public function test_copies_the_team_and_the_configured_currency(): void
    {
        config(['odden-sales.default_currency' => 'EUR']);
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create(['team_id' => 7]);

        $snapshot = app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, $pipeline->id);

        $deal = Deal::query()->findOrFail($snapshot?->id);
        $this->assertSame(7, $deal->team_id);
        $this->assertSame('EUR', $deal->currency);
    }

    public function test_records_the_first_stage_in_the_stage_history(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        $snapshot = app(DealGateway::class)->createOpenDeal(Contact::factory()->create(), 'Acme', 5000.0, $pipeline->id);

        $history = DealStageHistory::query()->where('deal_id', $snapshot?->id)->sole();
        $this->assertNull($history->from_stage_id);
        $this->assertSame($snapshot->stageId, $history->to_stage_id);
        $this->assertNull($history->exited_at);
    }

    public function test_returns_null_without_a_pipeline(): void
    {
        $contact = Contact::factory()->create();

        $this->assertNull(app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0));
        $this->assertNull(app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, 999));
    }

    public function test_find_many_returns_snapshots(): void
    {
        $deal = Deal::factory()->create();

        $found = app(DealGateway::class)->findMany([$deal->id]);

        $this->assertCount(1, $found);
        $this->assertSame($deal->id, $found->firstOrFail()->id);
    }
}
