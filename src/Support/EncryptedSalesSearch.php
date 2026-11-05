<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Areas\DataArea;
use Odden\Core\Encryption\EncryptedSearch;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;

final class EncryptedSalesSearch
{
    /**
     * @return Closure(Builder<Deal>, string): Builder<Deal>
     */
    public static function deals(): Closure
    {
        return static fn (Builder $query, string $search): Builder => EncryptedSearch::lacks(DataArea::Sales)
            ? $query->whereRaw('1 = 0')
            : $query->whereNameStartsWith($search);
    }

    /**
     * @return Closure(Builder<Quote>, string): Builder<Quote>
     */
    public static function quotes(): Closure
    {
        return static fn (Builder $query, string $search): Builder => EncryptedSearch::lacks(DataArea::Sales)
            ? $query->whereRaw('1 = 0')
            : $query->whereTitleStartsWith($search);
    }

    /**
     * @return Closure(Builder<Quote>, string): Builder<Quote>
     */
    public static function quotesOfDeals(): Closure
    {
        return static fn (Builder $query, string $search): Builder => EncryptedSearch::lacks(DataArea::Sales)
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('deal_id', Deal::query()->whereNameStartsWith($search)->select((new Deal)->getQualifiedKeyName()));
    }
}
