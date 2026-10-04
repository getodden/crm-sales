<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Odden\Sales\Actions\GenerateQuoteFromDealAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteLockedException;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_quote_from_deal_products(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Odden Enterprise License',
            'sku' => 'ODDEN-ENT',
            'unit_price' => 12000.00,
            'quantity' => 1,
            'discount_percent' => 0.00,
        ]);

        DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Implementation Support',
            'unit_price' => 3000.00,
            'quantity' => 1,
            'discount_percent' => 10.00, // $2,700
        ]);

        $action = new GenerateQuoteFromDealAction;
        $quote = $action->execute($deal, 'Annual Enterprise Contract');

        $this->assertSame('Annual Enterprise Contract', $quote->title);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertEquals(14700.00, $quote->total_amount);
        $this->assertCount(2, $quote->items);
        $this->assertNotNull($quote->public_token);
        $this->assertStringStartsWith('Q-', $quote->quote_number);
    }

    public function test_quote_can_be_accepted_with_digital_signature(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        $quote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'total_amount' => 5000.00,
        ]);

        $this->assertFalse($quote->status->isAccepted());

        $quote->accept('Jane Doe', 'jane@example.com');

        $this->assertTrue($quote->fresh()->status->isAccepted());
        $this->assertSame('Jane Doe', $quote->fresh()->signed_by_name);
        $this->assertSame('jane@example.com', $quote->fresh()->signed_by_email);
        $this->assertNotNull($quote->fresh()->accepted_at);
    }

    public function test_quote_expiration_detection(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        $activeQuote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'expires_at' => now()->addDays(5),
        ]);

        $expiredQuote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($activeQuote->isExpired());
        $this->assertTrue($expiredQuote->isExpired());
    }

    public function test_quote_item_missing_quantity_defaults_to_one_when_calculating_total_price(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);
        $quote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
        ]);

        $item = QuoteItem::create([
            'quote_id' => $quote->id,
            'name' => 'Default Quantity Item',
            'unit_price' => 400.00,
            'discount_percent' => 0.00,
        ]);

        $this->assertEquals(400.00, $item->total_price);
        $this->assertEquals(400.00, $item->fresh()->total_price);
        $this->assertEquals(400.00, $quote->fresh()->total_amount);
    }

    public function test_accept_refuses_a_quote_that_is_already_closed(): void
    {
        foreach ([QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired] as $status) {
            $quote = Quote::factory()->create(['status' => $status]);

            try {
                $quote->accept('Pat Doe', 'pat@example.com');
                $this->fail("A {$status->value} quote was accepted.");
            } catch (QuoteNotAcceptableException) {
                $this->assertSame($status, $quote->fresh()->status);
            }
        }
    }

    private function quoteWithItem(QuoteStatus $status = QuoteStatus::Sent): Quote
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages()->firstOrFail()->id]);
        $quote = Quote::factory()->create(['deal_id' => $deal->id, 'status' => $status, 'discount_amount' => 0, 'tax_amount' => 0]);
        QuoteItem::create(['quote_id' => $quote->id, 'name' => 'Licence', 'quantity' => 2, 'unit_price' => 500.00, 'discount_percent' => 0]);

        return $quote->fresh();
    }

    public function test_a_signed_quote_keeps_its_items_discount_and_tax(): void
    {
        $quote = $this->quoteWithItem();
        $quote->update(['status' => QuoteStatus::Accepted]);
        $item = $quote->items()->firstOrFail();

        $changes = [
            fn () => $item->update(['unit_price' => 1.00]),
            fn () => $item->delete(),
            fn () => QuoteItem::create(['quote_id' => $quote->id, 'name' => 'Extra', 'unit_price' => 10.00]),
            fn () => $quote->fresh()->update(['discount_amount' => 900.00]),
            fn () => $quote->fresh()->update(['tax_amount' => 50.00]),
        ];

        foreach ($changes as $change) {
            try {
                $change();
                $this->fail('A change to a signed quote was allowed.');
            } catch (QuoteLockedException) {
                // refused
            }
        }

        $signed = $quote->fresh();
        $this->assertEquals(1000.00, $signed->total_amount);
        $this->assertCount(1, $signed->items);
    }

    public function test_an_open_quote_can_still_be_edited(): void
    {
        $quote = $this->quoteWithItem();

        $quote->items()->firstOrFail()->update(['unit_price' => 600.00]);

        $this->assertEquals(1200.00, $quote->fresh()->total_amount);
    }

    public function test_changing_the_discount_or_tax_updates_the_total(): void
    {
        $quote = $this->quoteWithItem();

        $quote->update(['discount_amount' => 100.00, 'tax_amount' => 90.00]);

        $this->assertEquals(990.00, $quote->fresh()->total_amount);

        // With no items at all the total follows the discount and tax as well (it used to stay stale).
        $empty = Quote::factory()->create(['deal_id' => $quote->deal_id, 'discount_amount' => 0, 'tax_amount' => 0, 'subtotal' => 0, 'total_amount' => 0]);
        $empty->update(['tax_amount' => 25.00]);

        $this->assertEquals(25.00, $empty->fresh()->total_amount);
    }

    public function test_a_quote_number_that_is_already_taken_is_not_reused(): void
    {
        $first = Quote::factory()->create(['quote_number' => 'Q-'.now()->format('Y').'-AAAAA']);
        $sequence = ['AAAAA', 'BBBBB'];
        Str::createRandomStringsUsing(function (int $length) use (&$sequence): string {
            return $length === 5 ? (array_shift($sequence) ?? 'ZZZZZ') : str_repeat('t', $length);
        });

        try {
            $second = Quote::factory()->create(['quote_number' => null, 'deal_id' => $first->deal_id]);
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('Q-'.now()->format('Y').'-BBBBB', $second->quote_number);
    }
}
