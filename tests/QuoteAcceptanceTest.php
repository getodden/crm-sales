<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Enums\StageAutomationActionType;
use Odden\Sales\Events\DealMovedStage;
use Odden\Sales\Events\DealWon;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\StageAutomation;
use PHPUnit\Framework\Attributes\DataProvider;

class QuoteAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_public_quote_portal(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'name' => 'Acme Annual Agreement',
        ]);

        $quote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'subtotal' => 10000.00,
            'total_amount' => 10000.00,
            'public_token' => 'test-quote-token-xyz',
        ]);

        $quote->items()->create(['name' => 'Annual licence', 'quantity' => 12, 'unit_price' => 100, 'total_price' => 1200]);
        config(['app.name' => 'Northwind Supply']);

        $response = $this->get("/quotes/{$quote->public_token}");

        $response->assertSuccessful();
        $response->assertSee($quote->quote_number);
        $response->assertSee('Accept & Sign Proposal', false);
        // Who it's from, in the seller's name; the deal's name is the seller's own bookkeeping.
        $response->assertSee('from Northwind Supply');
        $response->assertDontSee('Acme Annual Agreement');
        $response->assertSee('<td class="num-col">12</td>', false);
    }

    public function test_can_accept_and_sign_quote_proposal(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
        ]);

        $quote = Quote::factory()->create([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'tax_amount' => 0.00,
            'total_amount' => 12500.00,
            'public_token' => 'sign-token-123',
        ]);

        $response = $this->post("/quotes/{$quote->public_token}/accept", [
            'signed_name' => 'Alice Johnson',
            'signed_email' => 'alice@acme.com',
            'agree_terms' => '1',
        ]);

        $response->assertRedirect("/quotes/{$quote->public_token}");
        $response->assertSessionHas('status');

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertSame('Alice Johnson', $quote->signed_by_name);
        $this->assertSame('alice@acme.com', $quote->signed_by_email);
        $this->assertNotNull($quote->accepted_at);

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame(12500.00, (float) $deal->amount);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function test_the_won_event_sees_the_signed_total_not_the_old_deal_amount(): void
    {
        $quote = $this->quoteOnOpenDeal(['total_amount' => 7300.00]);
        $quote->deal->update(['amount' => 100.00]);

        $seen = [];
        Event::listen(DealWon::class, function (DealWon $event) use (&$seen): void {
            $seen[] = (float) $event->deal->amount;
        });

        app(AcceptQuoteAction::class)->execute($quote->public_token, 'Alice', 'alice@acme.com');

        $this->assertSame([7300.0], $seen);
    }

    public function test_a_second_signed_quote_on_a_won_deal_does_not_win_it_again(): void
    {
        $first = $this->quoteOnOpenDeal(['total_amount' => 5000.00]);
        $deal = $first->deal;
        app(AcceptQuoteAction::class)->execute($first->public_token, 'Alice', 'alice@acme.com');

        $historyRows = DealStageHistory::query()->where('deal_id', $deal->id)->count();
        $second = Quote::factory()->create(['deal_id' => $deal->id, 'status' => QuoteStatus::Sent, 'tax_amount' => 0.00, 'total_amount' => 9000.00]);

        $won = 0;
        Event::listen(DealWon::class, function () use (&$won): void {
            $won++;
        });

        app(AcceptQuoteAction::class)->execute($second->public_token, 'Alice', 'alice@acme.com');

        $this->assertSame(QuoteStatus::Accepted, $second->fresh()?->status, 'The second signature is still recorded');
        $this->assertSame(0, $won, 'No second DealWon');
        $this->assertSame($historyRows, DealStageHistory::query()->where('deal_id', $deal->id)->count(), 'No second history row');
        $this->assertSame(5000.0, (float) $deal->fresh()?->amount, 'The deal keeps the amount it was won at');
    }

    public function test_the_deal_is_won_at_the_net_amount_in_the_quote_currency(): void
    {
        $quote = $this->quoteOnOpenDeal(['subtotal' => 10000.00, 'discount_amount' => 1000.00, 'tax_amount' => 1710.00, 'total_amount' => 10710.00, 'currency' => 'EUR']);

        app(AcceptQuoteAction::class)->execute($quote->public_token, 'Alice', 'alice@acme.com');

        $deal = $quote->deal->fresh();
        $this->assertSame(9000.0, (float) $deal?->amount, 'Tax is not counted as revenue');
        $this->assertSame('EUR', $deal->currency);
    }

    public function test_a_product_edit_after_the_signature_does_not_change_the_won_amount(): void
    {
        $quote = $this->quoteOnOpenDeal(['total_amount' => 5000.00]);
        app(AcceptQuoteAction::class)->execute($quote->public_token, 'Alice', 'alice@acme.com');

        DealProduct::factory()->create(['deal_id' => $quote->deal_id, 'unit_price' => 50.00, 'quantity' => 1, 'discount_percent' => 0]);

        $this->assertSame(5000.0, (float) $quote->deal->fresh()?->amount);
    }

    public function test_acceptance_locks_the_deal_row(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks.');
        }

        $quote = $this->quoteOnOpenDeal();
        DB::enableQueryLog();

        app(AcceptQuoteAction::class)->execute($quote->public_token, 'Alice', 'alice@acme.com');

        $dealLocks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'odden_deals')
            && str_contains(strtolower($query['query']), 'for update'));
        $this->assertNotEmpty($dealLocks);
    }

    public function test_a_quote_on_a_lost_deal_cannot_be_signed(): void
    {
        $quote = $this->quoteOnOpenDeal();
        $lost = $quote->deal->pipeline->stages->firstWhere('is_closed_lost', true);
        $quote->deal->moveToStage($lost);

        try {
            app(AcceptQuoteAction::class)->execute($quote->public_token, 'Alice', 'alice@acme.com');
            $this->fail('Expected the quote to be refused');
        } catch (QuoteNotAcceptableException $e) {
            $this->assertStringContainsString('no longer open', $e->getMessage());
        }

        $this->assertSame(QuoteStatus::Sent, $quote->fresh()?->status);
        $this->assertSame(DealStatus::Lost, $quote->deal->fresh()?->status);
    }

    public function test_moving_a_deal_to_the_stage_it_is_already_in_does_nothing(): void
    {
        $quote = $this->quoteOnOpenDeal();
        $deal = $quote->deal;
        $before = DealStageHistory::query()->where('deal_id', $deal->id)->count();

        $moved = 0;
        Event::listen(DealMovedStage::class, function () use (&$moved): void {
            $moved++;
        });

        $deal->moveToStage($deal->stage);

        $this->assertSame(0, $moved);
        $this->assertSame($before, DealStageHistory::query()->where('deal_id', $deal->id)->count());
    }

    public function test_a_quote_whose_deal_was_deleted_is_a_404_not_a_500(): void
    {
        $quote = $this->quoteOnOpenDeal(['public_token' => 'orphan-token']);
        $quote->deal->delete();

        $this->get('/quotes/orphan-token')->assertNotFound();

        $this->post('/quotes/orphan-token/accept', ['signed_name' => 'Alice', 'signed_email' => 'alice@acme.com', 'agree_terms' => '1'])
            ->assertSessionHasErrors('error');
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()?->status);
    }

    public function test_a_draft_that_was_never_sent_cannot_be_signed(): void
    {
        $quote = $this->quoteOnOpenDeal(['status' => QuoteStatus::Draft, 'public_token' => 'draft-token']);

        // Posting straight to the accept URL, without ever opening (and so sending) the proposal.
        $this->post('/quotes/draft-token/accept', ['signed_name' => 'Alice', 'signed_email' => 'alice@acme.com', 'agree_terms' => '1'])
            ->assertSessionHasErrors('error');

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()?->status);
        $this->assertSame(DealStatus::Open, $quote->deal->fresh()?->status);
    }

    protected function quoteOnOpenDeal(array $attributes = []): Quote
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
        ]);

        return Quote::factory()->create(array_merge([
            'deal_id' => $deal->id,
            'status' => QuoteStatus::Sent,
            'tax_amount' => 0.00,
            'total_amount' => 5000.00,
        ], $attributes));
    }

    /**
     * @return array<string, array{QuoteStatus}>
     */
    public static function nonAcceptableStatuses(): array
    {
        return [
            'declined' => [QuoteStatus::Declined],
            'expired' => [QuoteStatus::Expired],
        ];
    }

    #[DataProvider('nonAcceptableStatuses')]
    public function test_terminal_quotes_cannot_be_accepted_and_hide_the_sign_form(QuoteStatus $status): void
    {
        $quote = $this->quoteOnOpenDeal(['status' => $status]);

        $page = $this->get("/quotes/{$quote->public_token}");
        $page->assertSuccessful();
        $page->assertDontSee('Accept & Sign Proposal', false);
        $page->assertSee('no longer available for acceptance');

        $response = $this->post("/quotes/{$quote->public_token}/accept", [
            'signed_name' => 'Alice Johnson',
            'signed_email' => 'alice@acme.com',
            'agree_terms' => '1',
        ]);

        $response->assertRedirect("/quotes/{$quote->public_token}");
        $response->assertSessionHasErrors('error');

        $this->assertSame($status, $quote->refresh()->status);
        $this->assertNull($quote->signed_by_name);
        $this->assertSame(DealStatus::Open, $quote->deal->refresh()->status);
    }

    public function test_already_accepted_quote_cannot_be_signed_again(): void
    {
        $quote = $this->quoteOnOpenDeal([
            'status' => QuoteStatus::Accepted,
            'signed_by_name' => 'Original Signer',
            'signed_by_email' => 'original@acme.com',
        ]);

        $this->expectException(QuoteNotAcceptableException::class);

        try {
            app(AcceptQuoteAction::class)->execute($quote->public_token, 'Someone Else', 'else@acme.com');
        } finally {
            $this->assertSame('Original Signer', $quote->refresh()->signed_by_name);
        }
    }

    public function test_quote_can_be_accepted_on_its_expiry_day(): void
    {
        $quote = $this->quoteOnOpenDeal(['expires_at' => today()]);

        $this->get("/quotes/{$quote->public_token}")->assertSee('Accept & Sign Proposal', false);

        $this->post("/quotes/{$quote->public_token}/accept", [
            'signed_name' => 'Alice Johnson',
            'signed_email' => 'alice@acme.com',
            'agree_terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::Accepted, $quote->refresh()->status);
    }

    public function test_quote_past_its_expiry_date_cannot_be_accepted_and_expire_command_agrees(): void
    {
        $expiresToday = $this->quoteOnOpenDeal(['expires_at' => today()]);
        $pastExpiry = $this->quoteOnOpenDeal(['expires_at' => today()->subDay()]);

        $this->artisan('sales:expire-quotes')->assertSuccessful();

        $this->assertSame(QuoteStatus::Sent, $expiresToday->refresh()->status);
        $this->assertSame(QuoteStatus::Expired, $pastExpiry->refresh()->status);

        // A past-expiry quote the command has not reached yet is refused and marked expired.
        $notYetSwept = $this->quoteOnOpenDeal(['expires_at' => today()->subDay()]);

        $this->get("/quotes/{$notYetSwept->public_token}")->assertDontSee('Accept & Sign Proposal', false);

        $this->post("/quotes/{$notYetSwept->public_token}/accept", [
            'signed_name' => 'Alice Johnson',
            'signed_email' => 'alice@acme.com',
            'agree_terms' => '1',
        ])->assertSessionHasErrors('error');

        $this->assertSame(QuoteStatus::Expired, $notYetSwept->refresh()->status);
        $this->assertSame(DealStatus::Open, $notYetSwept->deal->refresh()->status);
    }

    public function test_failing_won_stage_requirement_rolls_back_acceptance_and_shows_a_generic_error(): void
    {
        Exceptions::fake();

        $quote = $this->quoteOnOpenDeal();

        /** @var PipelineStage $wonStage */
        $wonStage = $quote->deal->pipeline->stages()->where('is_closed_won', true)->firstOrFail();

        StageAutomation::create([
            'stage_id' => $wonStage->id,
            'name' => 'Require Products for Won',
            'action_type' => StageAutomationActionType::RequireDealProducts,
            'is_active' => true,
        ]);

        $response = $this->post("/quotes/{$quote->public_token}/accept", [
            'signed_name' => 'Alice Johnson',
            'signed_email' => 'alice@acme.com',
            'agree_terms' => '1',
        ]);

        $response->assertRedirect("/quotes/{$quote->public_token}");
        $response->assertSessionHasErrors('error');
        $message = (string) session('errors')->first('error');
        $this->assertStringNotContainsString('requires at least one line item', $message);
        $this->assertStringNotContainsString($wonStage->name, $message);

        Exceptions::assertReported(StageRequirementException::class);

        $quote->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        $this->assertNull($quote->accepted_at);
        $this->assertNull($quote->signed_by_name);

        $deal = $quote->deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertNotSame($wonStage->id, $deal->stage_id);
    }
}
