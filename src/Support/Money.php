<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Money
{
    /**
     * Currency symbols used as a prefix; any other currency is prefixed with its ISO code.
     *
     * @var array<string, string>
     */
    protected const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    /**
     * The currency used when a record has none.
     */
    public static function defaultCurrency(): string
    {
        return strtoupper((string) config('odden-sales.default_currency', 'USD'));
    }

    /**
     * The prefix for amounts in the currency: its symbol, or its ISO code and a space.
     */
    public static function prefix(?string $currency = null): string
    {
        $currency = strtoupper(trim((string) $currency));
        $currency = $currency === '' ? self::defaultCurrency() : $currency;

        return self::SYMBOLS[$currency] ?? $currency.' ';
    }

    /**
     * The currency a set of records should be reported in: the default currency when any record uses it,
     * otherwise the currency used by the most records (ties go to the alphabetically first), otherwise the
     * default. Amounts in different currencies must never be added together, so reports cover this one
     * currency and list the rest separately.
     *
     * @param  Builder<Model>  $query
     */
    public static function reportingCurrency(Builder $query, string $column = 'currency'): string
    {
        /** @var Collection<string, int> $counts */
        $counts = (clone $query)->reorder()->toBase()
            ->select($column)
            ->selectRaw('count(*) as odden_currency_count')
            ->groupBy($column)
            ->pluck('odden_currency_count', $column)
            ->mapWithKeys(fn ($count, $code): array => [strtoupper(trim((string) $code)) => (int) $count]);

        $default = self::defaultCurrency();

        if ($counts->isEmpty() || $counts->has($default)) {
            return $default;
        }

        return (string) $counts->sortKeys()->sortByDesc(fn (int $count): int => $count)->keys()->first();
    }

    /**
     * Totals of the records' `amount`, one per currency, formatted and joined: "$1,200.00 + €500.00".
     *
     * @param  iterable<Model>  $records
     */
    public static function formatTotals(iterable $records, string $amountAttribute = 'amount', int $decimals = 2): string
    {
        $totals = [];

        foreach ($records as $record) {
            $currency = strtoupper(trim((string) $record->getAttribute('currency')));
            $currency = $currency === '' ? self::defaultCurrency() : $currency;
            $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $record->getAttribute($amountAttribute);
        }

        if ($totals === []) {
            return self::format(0, null, $decimals);
        }

        ksort($totals);

        return implode(' + ', array_map(
            fn (string $currency, float $total): string => self::format($total, $currency, $decimals),
            array_keys($totals),
            $totals,
        ));
    }

    /**
     * Format an amount in the given currency, falling back to the configured default currency.
     */
    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            $currency = strtoupper((string) config('odden-sales.default_currency', 'USD'));
        }

        $number = number_format((float) $amount, $decimals);

        return isset(self::SYMBOLS[$currency])
            ? self::SYMBOLS[$currency].$number
            : $currency.' '.$number;
    }
}
