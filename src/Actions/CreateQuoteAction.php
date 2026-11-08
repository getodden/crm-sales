<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;

class CreateQuoteAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException
     */
    public function execute(array $attributes, int|string|null $userId = null): Quote
    {
        $attributes = Arr::except($attributes, Quote::SYSTEM_ATTRIBUTES);

        $status = $attributes['status'] ?? null;

        if ($status === QuoteStatus::Accepted || $status === QuoteStatus::Accepted->value) {
            unset($attributes['status']);
        }

        $deal = Deal::query()->withTrashed()->whereKey($attributes['deal_id'] ?? null)->firstOrFail();

        $attributes['currency'] = $deal->currency;
        $attributes['user_id'] ??= $deal->owner_id ?? $userId;

        return DB::transaction(fn (): Quote => Quote::create($attributes));
    }
}
