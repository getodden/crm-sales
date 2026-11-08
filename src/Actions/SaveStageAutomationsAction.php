<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Actions\SyncChildRecordsAction;
use Odden\Sales\Models\PipelineStage;

class SaveStageAutomationsAction
{
    /**
     * @param  list<array<string, mixed>>  $automations
     */
    public function execute(PipelineStage $stage, array $automations): PipelineStage
    {
        app(SyncChildRecordsAction::class)->execute($stage->automations(), $automations, null);

        return $stage;
    }
}
