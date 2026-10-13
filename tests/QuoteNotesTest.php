<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Odden\Sales\Actions\GenerateQuoteFromDealAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;

/**
 * A quote has two sets of notes: the ones the customer reads on the quote page, and the rep's own, which nothing public renders.
 */
class QuoteNotesTest extends TestCase
{
    use RefreshDatabase;

    private function dealWithQuote(array $quote = []): Quote
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'name' => 'Acme Annual Agreement']);

        return Quote::factory()->create(array_merge([
            'deal_id' => $deal->id, 'status' => QuoteStatus::Sent, 'public_token' => 'notes-token-123',
        ], $quote));
    }

    public function test_the_quote_page_shows_the_customer_notes_and_never_the_internal_ones(): void
    {
        $quote = $this->dealWithQuote([
            'notes' => 'Prices hold until the end of the quarter.',
            'internal_notes' => 'Client is price-sensitive: can go 15% lower.',
        ]);

        $this->get("/quotes/{$quote->public_token}")
            ->assertSuccessful()
            ->assertSee('Prices hold until the end of the quarter.')
            ->assertDontSee('price-sensitive')
            ->assertDontSee('15% lower');
    }

    public function test_internal_notes_alone_leave_no_notes_block_on_the_page(): void
    {
        $quote = $this->dealWithQuote(['notes' => null, 'terms' => null, 'internal_notes' => 'Do not discount below 10%.']);

        $this->get("/quotes/{$quote->public_token}")->assertSuccessful()->assertDontSee('Do not discount below 10%');
    }

    public function test_a_quote_generated_from_a_deal_can_carry_internal_notes(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

        $quote = app(GenerateQuoteFromDealAction::class)->execute($deal, notes: 'Visible to the customer', internalNotes: 'Team only');

        $this->assertSame('Visible to the customer', $quote->notes);
        $this->assertSame('Team only', $quote->internal_notes);
        $this->assertTrue(Schema::hasColumn($quote->getTable(), 'internal_notes'));
    }
}
