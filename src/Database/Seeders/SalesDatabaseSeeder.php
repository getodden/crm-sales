<?php

declare(strict_types=1);

namespace Odden\Sales\Database\Seeders;

use Illuminate\Database\Seeder;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class SalesDatabaseSeeder extends Seeder
{
    /**
     * Run the sales module seeds.
     */
    public function run(): void
    {
        // 1. Ensure Sales Rep Users
        $admin = UserModel::query()->firstOrCreate(
            ['email' => 'admin@odden.test'],
            ['name' => 'Odden Admin', 'password' => bcrypt('password')]
        );

        $repBeth = UserModel::query()->firstOrCreate(
            ['email' => 'beth.caldwell@odden.test'],
            ['name' => 'Beth Caldwell', 'password' => bcrypt('password')]
        );

        $repMark = UserModel::query()->firstOrCreate(
            ['email' => 'mark.hunter@odden.test'],
            ['name' => 'Mark Hunter', 'password' => bcrypt('password')]
        );

        // 2. Playbooks (BANT & MEDDIC)
        SalesPlaybook::firstOrCreate(
            ['slug' => 'bant-qualification'],
            array_merge(SalesPlaybook::defaultBantPreset(), ['user_id' => $admin->getKey()])
        );

        SalesPlaybook::firstOrCreate(
            ['slug' => 'meddic-enterprise'],
            array_merge(SalesPlaybook::defaultMeddicPreset(), ['user_id' => $admin->getKey()])
        );

        // 3. Email Templates with merge tags
        $templateOutbound = SalesEmailTemplate::firstOrCreate(
            ['name' => 'Cold Outbound - Enterprise Intro'],
            [
                'subject' => 'Accelerating growth for {{ contact.company_name }}',
                'body_html' => '<p>Hi {{ contact.first_name }},</p><p>I noticed your recent expansion at {{ contact.company_name }}. At Odden, we help enterprise sales teams accelerate cycle times by 35%.</p><p>Would you have 15 minutes this week for a brief introductory conversation?</p><p>Best regards,<br>{{ user.name }}</p>',
                'category' => 'outbound',
                'user_id' => $repBeth->getKey(),
                'is_shared' => true,
            ]
        );

        $templateFollowup = SalesEmailTemplate::firstOrCreate(
            ['name' => 'Inbound Fast Response'],
            [
                'subject' => 'Thanks for checking out Odden, {{ contact.first_name }}!',
                'body_html' => '<p>Hi {{ contact.first_name }},</p><p>Thanks for requesting details on our platform. I’d love to learn more about your current tech stack and share a customized demo.</p><p>Feel free to grab a time that works on my calendar.</p><p>Best regards,<br>{{ user.name }}</p>',
                'category' => 'inbound',
                'user_id' => $repBeth->getKey(),
                'is_shared' => true,
            ]
        );

        $templateBreakup = SalesEmailTemplate::firstOrCreate(
            ['name' => 'Breakup & Permission to Close File'],
            [
                'subject' => 'Permission to close your file, {{ contact.first_name }}?',
                'body_html' => '<p>Hi {{ contact.first_name }},</p><p>I haven\'t heard back regarding {{ deal.name }}, so I assume priorities may have shifted. If now isn\'t the right time, no worries at all!</p><p>I\'ll mark this on pause for now. Feel free to reach back out whenever you\'re ready.</p><p>Best regards,<br>{{ user.name }}</p>',
                'category' => 'closing',
                'user_id' => $repMark->getKey(),
                'is_shared' => true,
            ]
        );

        // 4. Sales Cadences / Sequences
        $outboundSequence = SalesSequence::firstOrCreate(
            ['name' => 'Enterprise Outbound 14-Day Cadence'],
            [
                'description' => 'Multi-touch cadence combining automated email, discovery calls, and LinkedIn touches.',
                'steps' => [
                    [
                        'step' => 1,
                        'type' => 'automated_email',
                        'delay_days' => 0,
                        'title' => 'Initial Outreach Email',
                        'template_id' => $templateOutbound->id,
                    ],
                    [
                        'step' => 2,
                        'type' => 'manual_call',
                        'delay_days' => 2,
                        'title' => 'Follow-up Discovery Phone Call',
                    ],
                    [
                        'step' => 3,
                        'type' => 'manual_task',
                        'delay_days' => 3,
                        'title' => 'Connect & InMail on LinkedIn',
                    ],
                    [
                        'step' => 4,
                        'type' => 'automated_email',
                        'delay_days' => 4,
                        'title' => 'Final Breakup Email',
                        'template_id' => $templateBreakup->id,
                    ],
                ],
                'is_active' => true,
                'user_id' => $repBeth->getKey(),
            ]
        );

        $inboundSequence = SalesSequence::firstOrCreate(
            ['name' => 'Inbound Fast Response Cadence'],
            [
                'description' => 'Fast touch sequence for incoming website demo inquiries.',
                'steps' => [
                    [
                        'step' => 1,
                        'type' => 'automated_email',
                        'delay_days' => 0,
                        'title' => 'Instant Welcome & Demo Schedule Email',
                        'template_id' => $templateFollowup->id,
                    ],
                    [
                        'step' => 2,
                        'type' => 'manual_call',
                        'delay_days' => 1,
                        'title' => 'Intro Call & Qualification',
                    ],
                ],
                'is_active' => true,
                'user_id' => $admin->getKey(),
            ]
        );

        // 5. Sequence Enrollments
        $sampleContacts = Contact::query()->take(4)->get();
        $firstContact = $sampleContacts->first();
        $secondContact = $sampleContacts->skip(1)->first();

        if ($firstContact instanceof Contact && $secondContact instanceof Contact) {
            SalesSequenceEnrollment::firstOrCreate(
                [
                    'sequence_id' => $outboundSequence->id,
                    'contact_id' => $firstContact->id,
                ],
                [
                    'current_step' => 1,
                    'status' => 'active',
                    'next_step_due_at' => now()->toDateString(),
                    'enrolled_by_id' => $repBeth->getKey(),
                ]
            );

            SalesSequenceEnrollment::firstOrCreate(
                [
                    'sequence_id' => $inboundSequence->id,
                    'contact_id' => $secondContact->id,
                ],
                [
                    'current_step' => 2,
                    'status' => 'active',
                    'next_step_due_at' => now()->addDay()->toDateString(),
                    'enrolled_by_id' => $admin->getKey(),
                ]
            );
        }

        // 6. Meeting Links
        SalesMeetingLink::firstOrCreate(
            ['slug' => 'beth-caldwell'],
            [
                'user_id' => $repBeth->getKey(),
                'title' => '30 Min Discovery Strategy Session',
                'duration_minutes' => 30,
                'description' => 'Schedule a strategic discovery session with Beth to align on platform architecture and timelines.',
                'is_active' => true,
            ]
        );

        SalesMeetingLink::firstOrCreate(
            ['slug' => 'mark-hunter'],
            [
                'user_id' => $repMark->getKey(),
                'title' => '15 Min Executive Overview',
                'duration_minutes' => 15,
                'description' => 'A brief alignment call with Mark Hunter covering high-level platform capabilities.',
                'is_active' => true,
            ]
        );

        // 7. Lead Routing Rules
        LeadRoutingRule::firstOrCreate(
            ['name' => 'Inbound Enterprise Round Robin'],
            [
                'strategy' => LeadRoutingStrategy::RoundRobin,
                'criteria' => ['min_amount' => 50000],
                'assigned_user_ids' => [$repBeth->getKey(), $repMark->getKey()],
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        LeadRoutingRule::firstOrCreate(
            ['name' => 'West Coast Tech Territory'],
            [
                'strategy' => LeadRoutingStrategy::Territory,
                'criteria' => ['timezone' => 'America/Los_Angeles'],
                'assigned_user_ids' => [$repBeth->getKey()],
                'sort_order' => 2,
                'is_active' => true,
            ]
        );

        // 8. Deals with Varied Stages and Realistic Health Scores
        $pipeline = Pipeline::where('is_default', true)->first();
        if ($pipeline) {
            $stages = PipelineStage::where('pipeline_id', $pipeline->id)->orderBy('sort_order')->get();
            $discoveryStage = $stages->firstWhere('code', 'discovery') ?? $stages->first();
            $proposalStage = $stages->firstWhere('code', 'proposal_sent') ?? $stages[min(2, $stages->count() - 1)];
            $negotiationStage = $stages->firstWhere('code', 'negotiation') ?? $stages[min(3, $stages->count() - 1)];

            $companies = Company::take(3)->get();
            $firstCompany = $companies->first();
            $firstContact = $sampleContacts->first();

            // High Health Deal (80-100: recent activity, upcoming close date, active quote)
            if ($negotiationStage) {
                $healthyDeal = Deal::firstOrCreate(
                    ['name' => 'Acme Global - Enterprise Multi-Year Expansion'],
                    [
                        'pipeline_id' => $pipeline->id,
                        'stage_id' => $negotiationStage->id,
                        'amount' => 180000.00,
                        'currency' => 'USD',
                        'status' => DealStatus::Open,
                        'expected_close_date' => now()->addDays(14),
                        'owner_id' => $repBeth->getKey(),
                    ]
                );

                if ($firstCompany) {
                    $healthyDeal->associateWith($firstCompany, 'primary_company');
                }
                if ($firstContact) {
                    $healthyDeal->associateWith($firstContact, 'primary_contact');
                }

                $healthyDeal->logCall('Negotiation Deep Dive', 'Discussed terms, security audit passed, legal reviewing MSA.', [
                    'duration_seconds' => 1800,
                ]);

                $quote = Quote::firstOrCreate(
                    ['deal_id' => $healthyDeal->id, 'title' => 'Enterprise License Agreement - Acme'],
                    [
                        'quote_number' => 'Q-2026-ACME-01',
                        'public_token' => 'quote-acme-'.substr(md5((string) $healthyDeal->id), 0, 12),
                        'status' => QuoteStatus::Sent,
                        'subtotal' => 180000.00,
                        'discount_amount' => 0.00,
                        'tax_amount' => 0.00,
                        'total_amount' => 180000.00,
                        'currency' => 'USD',
                        'expires_at' => now()->addDays(30),
                        'user_id' => $repBeth->getKey(),
                    ]
                );

                QuoteItem::firstOrCreate(
                    ['quote_id' => $quote->id, 'name' => 'Odden Enterprise Seat (Annual)'],
                    [
                        'quantity' => 100,
                        'unit_price' => 1800.00,
                        'discount_percent' => 0.00,
                        'total_price' => 180000.00,
                        'sort_order' => 1,
                    ]
                );
            }

            // Warning Deal (40-69: no recent touch in 10 days, close date approaching)
            if ($proposalStage) {
                $warningDeal = Deal::firstOrCreate(
                    ['name' => 'Soylent Corp - Supply Chain Platform'],
                    [
                        'pipeline_id' => $pipeline->id,
                        'stage_id' => $proposalStage->id,
                        'amount' => 64000.00,
                        'currency' => 'USD',
                        'status' => DealStatus::Open,
                        'expected_close_date' => now()->addDays(3),
                        'owner_id' => $repMark->getKey(),
                    ]
                );

                // Stage entered 18 days ago
                DealStageHistory::firstOrCreate(
                    ['deal_id' => $warningDeal->id, 'to_stage_id' => $proposalStage->id],
                    [
                        'from_stage_id' => $discoveryStage?->id,
                        'user_id' => $repMark->getKey(),
                        'entered_at' => now()->subDays(18),
                    ]
                );
            }

            // Critical Deal (<40: overdue close date, stalled in discovery for 45 days)
            if ($discoveryStage) {
                $criticalDeal = Deal::firstOrCreate(
                    ['name' => 'Starlight Media - Legacy Migration'],
                    [
                        'pipeline_id' => $pipeline->id,
                        'stage_id' => $discoveryStage->id,
                        'amount' => 45000.00,
                        'currency' => 'USD',
                        'status' => DealStatus::Open,
                        'expected_close_date' => now()->subDays(10), // Overdue
                        'owner_id' => $admin->getKey(),
                    ]
                );

                DealStageHistory::firstOrCreate(
                    ['deal_id' => $criticalDeal->id, 'to_stage_id' => $discoveryStage->id],
                    [
                        'from_stage_id' => null,
                        'user_id' => $admin->getKey(),
                        'entered_at' => now()->subDays(45),
                    ]
                );
            }
        }
    }
}
