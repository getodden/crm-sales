<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;

class UpdateQuoteAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException
     */
    public function execute(Quote $quote, array $attributes): Quote
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($quote);

        $attributes = Arr::except($attributes, ['user_id', ...Quote::SYSTEM_ATTRIBUTES]);
        $attributes['currency'] = Deal::query()->withTrashed()->whereKey($attributes['deal_id'] ?? $quote->deal_id)->firstOrFail()->currency;

        return DB::transaction(function () use ($quote, $attributes): Quote {
            $quote->fill($attributes)->save();

            return $quote;
        });
    }
}
