<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Arr;
use Odden\Core\Support\PropertyMerge;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Deal;

class UpdateDealAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Deal $deal, array $attributes): Deal
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($deal);

        $attributes = Arr::except($attributes, ['pipeline_id', 'stage_id', 'status', 'closed_at']);

        $deal->fill(PropertyMerge::onto($deal, $attributes))->save();

        return $deal;
    }
}
