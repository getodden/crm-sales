<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesPlaybook;

class SalesPlaybookTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_execute_bant_playbook_and_sync_properties(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
        ]);

        $playbook = SalesPlaybook::query()->create(SalesPlaybook::defaultBantPreset());

        $answers = [
            'budget_status' => 'Allocated & Approved',
            'authority_role' => 'Final Economic Buyer',
            'core_pain' => 'High manual churn in legacy billing software.',
            'target_timeline' => 'This Quarter (1-3 months)',
        ];

        $action = new ExecuteSalesPlaybookAction;
        $action->execute($deal, $playbook, $answers);

        $deal->refresh();
        $this->assertSame('Allocated & Approved', $deal->getProperty('qualification_budget'));
        $this->assertSame('Final Economic Buyer', $deal->getProperty('qualification_authority'));
        $this->assertSame('This Quarter (1-3 months)', $deal->getProperty('qualification_timeline'));

        $this->assertTrue(Activity::query()->where([
            'subject_type' => $deal->getMorphClass(),
            'subject_id' => $deal->id,
            'type' => ActivityType::Note->value,
        ])->get()->contains(fn (Activity $row): bool => $row->title == 'Playbook: BANT Qualification Playbook'));
    }
}
