<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class SavePipelineStageAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException
     */
    public function execute(Pipeline $pipeline, array $attributes, ?PipelineStage $stage = null): PipelineStage
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($pipeline);

        if ($stage !== null && (string) $stage->pipeline_id !== (string) $pipeline->getKey()) {
            throw (new ModelNotFoundException)->setModel(PipelineStage::class, [$stage->getKey()]);
        }

        $attributes = Arr::except($attributes, ['pipeline_id']);

        return DB::transaction(function () use ($pipeline, $attributes, $stage): PipelineStage {
            if ($stage === null) {
                return $pipeline->stages()->create($attributes);
            }

            $stage->fill($attributes)->save();

            return $stage;
        });
    }
}
