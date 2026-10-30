<?php

declare(strict_types=1);

namespace Odden\Sales\Demo;

use Illuminate\Support\Str;
use Odden\Core\Demo\CoreDemoSeeder;
use Odden\Core\Demo\DemoContext;
use Odden\Core\Demo\DemoSeeder;
use Odden\Core\Demo\PreparesDemoRemoval;
use Odden\Core\Models\Contact;
use Odden\Core\Models\DemoRecord;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuotaPeriod;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

/**
 * Sample pipeline, deals, templates, cadences, quotas and quotes, attached to the sample companies and contacts from Core.
 */
final class SalesDemoSeeder implements DemoSeeder, PreparesDemoRemoval
{
    public function module(): string
    {
        return 'Sales';
    }

    public function order(): int
    {
        return 10;
    }

    public function seed(DemoContext $context): void
    {
        $deals = $this->pipelineAndDeals($context);
        $templates = $this->templates($context);
        $this->sequences($context, $templates);
        $this->quotas($context);
        $this->quotes($context, $deals);
        $this->ownerTools($context);
    }

    /**
     * Anyone can book the sample booking page, and a link with upcoming bookings refuses to be deleted. Cancelling them here lets the
     * sample data go and fires MeetingBookingCancelled for each, so no booking disappears unannounced.
     */
    public function prepareRemoval(): void
    {
        $sampleLinks = DemoRecord::query()->where('model_type', (new SalesMeetingLink)->getMorphClass())->pluck('model_id')->map(fn (string $id): int => (int) $id);

        SalesMeetingBooking::query()
            ->whereIn('meeting_link_id', $sampleLinks)
            ->upcoming()
            ->each(fn (SalesMeetingBooking $booking): SalesMeetingBooking => $booking->cancel());
    }

    /**
     * @return list<Deal>
     */
    private function pipelineAndDeals(DemoContext $context): array
    {
        $pipeline = $context->track(Pipeline::query()->create([
            'name' => 'Sample pipeline',
            'code' => 'demo-'.Str::lower(Str::random(6)),
            'is_default' => ! Pipeline::query()->where('is_default', true)->exists(),
            'is_active' => true,
        ]));

        $stages = [];

        foreach ([['Qualified', 'qualified', 20, false, false], ['Proposal', 'proposal', 50, false, false], ['Negotiation', 'negotiation', 75, false, false], ['Won', 'won', 100, true, false], ['Lost', 'lost', 0, false, true]] as $i => [$name, $code, $probability, $won, $lost]) {
            $stages[$code] = $context->track(PipelineStage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'name' => $name,
                'code' => $code,
                'probability' => $probability,
                'sort_order' => $i + 1,
                'is_closed_won' => $won,
                'is_closed_lost' => $lost,
            ]));
        }

        $rows = [
            ['Seat expansion', 'maya', 'proposal', 26000, DealStatus::Open, 21],
            ['Onboarding services', 'maya', 'qualified', 12500, DealStatus::Open, 35],
            ['Annual renewal', 'maya', 'won', 24000, DealStatus::Won, -2],
            ['Fleet tracking rollout', 'daniel', 'negotiation', 18000, DealStatus::Open, 12],
            ['Wholesale portal', 'priya', 'proposal', 13000, DealStatus::Open, 28],
            ['Finance suite upgrade', 'tom', 'negotiation', 40000, DealStatus::Open, 9],
            ['Clinical API access', 'elena', 'qualified', 12000, DealStatus::Open, 45],
            ['Starter plan', 'owen', 'lost', 3000, DealStatus::Lost, -20],
        ];

        $deals = [];

        foreach ($rows as [$name, $who, $stage, $amount, $status, $closeIn]) {
            $contact = $context->find(CoreDemoSeeder::GROUP_CONTACTS, $who);

            if (! $contact instanceof Contact) {
                continue;
            }

            $deal = $context->track(Deal::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => ($stages[$stage] ?? throw new \LogicException("Sample stage {$stage} was not created."))->getKey(),
                'name' => $name,
                'amount' => $amount,
                'currency' => 'USD',
                'status' => $status,
                'expected_close_date' => now()->addDays(abs($closeIn)),
                'closed_at' => $status === DealStatus::Open ? null : now()->addDays($closeIn),
                'lost_reason' => $status === DealStatus::Lost ? 'Chose a competitor' : null,
                'owner_id' => $context->ownerId(),
            ]));

            $deal->associateWith($contact);
            $company = $contact->companies()->first();

            if ($company !== null) {
                $deal->associateWith($company);
            }

            $deals[] = $deal;
        }

        return $deals;
    }

    /**
     * @return array<string, SalesEmailTemplate>
     */
    private function templates(DemoContext $context): array
    {
        $rows = [
            'intro' => ['Cold intro', 'Quick question about {{company}}', 'introduction', 'Hi {{first_name}}, I noticed {{company}} is growing its operations team. We help teams like yours cut reporting time by half. Worth a short call this week?'],
            'follow' => ['Gentle follow-up', 'Following up on my note', 'follow_up', 'Hi {{first_name}}, bumping this in case it got buried. Happy to send a two-minute walkthrough instead of a call.'],
            'case' => ['Case study share', 'How a team like yours cut onboarding by 40%', 'follow_up', 'Hi {{first_name}}, thought this story from a similar team might help. The short version: fewer handoffs, faster first value.'],
            'proposal' => ['Proposal sent', 'Your proposal', 'proposal', 'Hi {{first_name}}, the proposal is attached. It covers the scope we discussed, pricing for 12 months and the rollout plan. Questions welcome.'],
            'close' => ['Nudge to close', 'Ready to get started?', 'closing', 'Hi {{first_name}}, if the numbers work on your side, I can have the paperwork to you today and your team live by the end of the month.'],
            'breakup' => ['Breakup note', 'Should I close your file?', 'general', 'Hi {{first_name}}, I have not heard back, so I will assume the timing is off. If that changes, reply any time.'],
        ];

        $made = [];

        foreach ($rows as $key => [$name, $subject, $category, $body]) {
            $made[$key] = $context->track(SalesEmailTemplate::query()->create([
                'name' => 'Sample: '.$name,
                'subject' => $subject,
                'category' => $category,
                'body_html' => '<p>'.$body.'</p>',
                'user_id' => $context->ownerId(),
                'is_shared' => true,
            ]));
        }

        return $made;
    }

    /**
     * @param  array<string, SalesEmailTemplate>  $templates
     */
    private function sequences(DemoContext $context, array $templates): void
    {
        $cold = $context->track(SalesSequence::query()->create([
            'name' => 'Sample: New lead outreach',
            'description' => 'Five touches over two weeks for a lead who has not replied.',
            'is_active' => true,
            'user_id' => $context->ownerId(),
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'Intro email', 'template_id' => $templates['intro']->getKey()],
                ['step' => 2, 'type' => 'linkedin', 'delay_days' => 2, 'title' => 'Connect on LinkedIn'],
                ['step' => 3, 'type' => 'email', 'delay_days' => 3, 'title' => 'Follow-up with a case study', 'template_id' => $templates['case']->getKey()],
                ['step' => 4, 'type' => 'call', 'delay_days' => 4, 'title' => 'Call and leave a voicemail'],
                ['step' => 5, 'type' => 'email', 'delay_days' => 5, 'title' => 'Breakup note', 'template_id' => $templates['breakup']->getKey()],
            ],
        ]));

        $proposal = $context->track(SalesSequence::query()->create([
            'name' => 'Sample: Proposal follow-through',
            'description' => 'Keeps a sent proposal moving without nagging.',
            'is_active' => true,
            'user_id' => $context->ownerId(),
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 1, 'title' => 'Check the proposal arrived', 'template_id' => $templates['proposal']->getKey()],
                ['step' => 2, 'type' => 'task', 'delay_days' => 3, 'title' => 'Book a review call'],
                ['step' => 3, 'type' => 'email', 'delay_days' => 4, 'title' => 'Nudge to close', 'template_id' => $templates['close']->getKey()],
            ],
        ]));

        $context->track(SalesSequence::query()->create([
            'name' => 'Sample: Win-back',
            'description' => 'For lost deals older than ninety days. Paused while the offer is reworked.',
            'is_active' => false,
            'user_id' => $context->ownerId(),
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'What has changed', 'template_id' => $templates['follow']->getKey()],
                ['step' => 2, 'type' => 'call', 'delay_days' => 5, 'title' => 'Check in by phone'],
            ],
        ]));

        foreach ([[$cold, 'samir', 2, 'active', 1], [$cold, 'owen', 4, 'active', 2], [$cold, 'grace', 5, 'completed', null], [$proposal, 'priya', 2, 'active', 0], [$proposal, 'elena', 1, 'paused', 6]] as [$sequence, $who, $step, $status, $dueInDays]) {
            $contact = $context->find(CoreDemoSeeder::GROUP_CONTACTS, $who);

            if ($contact instanceof Contact) {
                $context->track(SalesSequenceEnrollment::query()->create([
                    'sequence_id' => $sequence->getKey(),
                    'contact_id' => $contact->getKey(),
                    'current_step' => $step,
                    'status' => $status,
                    'next_step_due_at' => $dueInDays === null ? null : now()->addDays($dueInDays)->toDateString(),
                    'enrolled_by_id' => $context->ownerId(),
                    'enrolled_at' => now()->subDays(6),
                ]));
            }
        }
    }

    private function quotas(DemoContext $context): void
    {
        if ($context->ownerId() === null) {
            return;
        }

        foreach ([[QuotaPeriod::Monthly, now()->startOfMonth(), now()->endOfMonth(), 60000], [QuotaPeriod::Quarterly, now()->firstOfQuarter(), now()->lastOfQuarter(), 180000], [QuotaPeriod::Yearly, now()->startOfYear(), now()->endOfYear(), 720000]] as [$period, $start, $end, $target]) {
            if (SalesQuota::query()->where('user_id', $context->ownerId())->where('period_type', $period)->whereDate('period_start', $start->toDateString())->exists()) {
                continue;
            }

            $context->track(SalesQuota::query()->create([
                'user_id' => $context->ownerId(),
                'period_type' => $period,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'target_amount' => $target,
                'currency' => 'USD',
            ]));
        }
    }

    /**
     * @param  list<Deal>  $deals
     */
    private function quotes(DemoContext $context, array $deals): void
    {
        $statuses = ['accepted', 'sent', 'draft', 'declined', 'approved'];

        foreach (array_slice($deals, 0, 5) as $i => $deal) {
            $items = [['Platform licence', 'LIC-12', 12, 1000 + $i * 150], ['Onboarding and training', 'SVC-ONB', 1, 2500]];
            $subtotal = collect($items)->sum(fn (array $item): int => $item[2] * $item[3]);
            $discount = $i === 0 ? round($subtotal * 0.05, 2) : 0;
            $tax = round(($subtotal - $discount) * 0.08, 2);

            $quote = $context->track(Quote::query()->create([
                'quote_number' => sprintf('SAMPLE-%d-%04d', now()->year, $i + 1),
                'deal_id' => $deal->getKey(),
                'title' => $deal->name,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $subtotal - $discount + $tax,
                'currency' => 'USD',
                'terms' => 'Net 30. Prices valid until the expiry date. Licences renew annually.',
                'public_token' => Str::random(32),
                'expires_at' => now()->addDays(21 - $i * 10)->toDateString(),
                'user_id' => $context->ownerId(),
            ]));

            foreach ($items as $n => [$name, $sku, $quantity, $price]) {
                $quote->items()->create(['name' => $name, 'sku' => $sku, 'unit_price' => $price, 'quantity' => $quantity, 'discount_percent' => 0, 'total_price' => $quantity * $price, 'sort_order' => $n]);
            }

            if ($statuses[$i] === 'accepted') {
                $quote->accept('Maya Okafor', 'maya@kestrellabs.example');
            } elseif ($statuses[$i] !== 'draft') {
                $quote->forceFill(['status' => $statuses[$i]])->save();
            }
        }
    }

    /**
     * A playbook and a booking page belong to a person, so they are only added when there is a user to own them.
     */
    private function ownerTools(DemoContext $context): void
    {
        if ($context->ownerId() === null) {
            return;
        }

        if (! SalesPlaybook::query()->where('slug', 'sample-bant')->exists()) {
            $context->track(SalesPlaybook::query()->create(array_merge(SalesPlaybook::defaultBantPreset(), ['slug' => 'sample-bant', 'user_id' => $context->ownerId()])));
        }

        if (! SalesMeetingLink::query()->where('slug', 'sample-intro-call')->exists()) {
            $context->track(SalesMeetingLink::query()->create([
                'slug' => 'sample-intro-call',
                'user_id' => $context->ownerId(),
                'title' => '30 minute intro call',
                'duration_minutes' => 30,
                'description' => 'Pick a time for a first conversation.',
                'is_active' => true,
            ]));
        }
    }
}
