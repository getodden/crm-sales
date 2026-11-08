<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Pipeline;

class SavePipelineAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, ?Pipeline $pipeline = null): Pipeline
    {
        $pipeline ??= new Pipeline;

        if ($pipeline->exists) {
            app(TenantGuard::class)->assertOwnedWhenTenanted($pipeline);
        }

        return DB::transaction(function () use ($pipeline, $attributes): Pipeline {
            $pipeline->fill($attributes)->save();

            $this->keepSingleDefault($pipeline);

            return $pipeline;
        });
    }

    private function keepSingleDefault(Pipeline $pipeline): void
    {
        if (! $pipeline->is_default) {
            return;
        }

        Pipeline::query()
            ->whereKeyNot($pipeline->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
