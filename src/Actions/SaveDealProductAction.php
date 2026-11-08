<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;

class SaveDealProductAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException
     */
    public function execute(Deal $deal, array $attributes, ?DealProduct $product = null): DealProduct
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($deal);

        if ($product !== null && (string) $product->deal_id !== (string) $deal->getKey()) {
            throw (new ModelNotFoundException)->setModel(DealProduct::class, [$product->getKey()]);
        }

        $attributes = Arr::except($attributes, ['deal_id']);

        return DB::transaction(function () use ($deal, $attributes, $product): DealProduct {
            if ($product === null) {
                return $deal->products()->create($attributes);
            }

            $product->fill($attributes)->save();

            return $product;
        });
    }
}
