<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Sales\Models\Quote;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A quote is titled after its deal and its lines can be named after the deal too, a product line is whatever the seller typed, the
     * reason a deal was lost is free text, and the conditions of a routing rule or a stage automation hold values. All are stored
     * encrypted with the workspace key. The columns become text, because the stored form is longer than the value. Quotes are found
     * by title through the search index, which is built from the rows already stored.
     */
    public function up(): void
    {
        foreach ($this->columns() as [$table, $column, $field, $nullable]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable): void {
                $blueprint->text($column)->nullable($nullable)->change();
            });
        }

        if (! config('odden-core.encryption.convert_on_migrate', true)) {
            return;
        }

        $converter = app(ColumnConverter::class);

        foreach ($this->columns() as [$table, $column, $field]) {
            $converter->encrypt($table, $column, $field);
        }

        if (Schema::hasTable(config('odden-sales.tables.quotes', 'odden_quotes'))) {
            app(SearchIndex::class)->rebuild(Quote::class);
        }
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first, and the quotes' index rows go.
     */
    public function down(): void
    {
        $converter = app(ColumnConverter::class);

        foreach ($this->columns() as [$table, $column, $field]) {
            $converter->decrypt($table, $column, $field);
        }

        if (Schema::hasTable($index = config('odden-core.tables.search_index', 'odden_search_index'))) {
            DB::table($index)->where('field', 'like', 'quotes.%')->delete();
        }
    }

    /**
     * @return list<array{string, string, string, bool}>
     */
    private function columns(): array
    {
        $deals = config('odden-sales.tables.deals', 'odden_deals');
        $quotes = config('odden-sales.tables.quotes', 'odden_quotes');
        $items = config('odden-sales.tables.quote_items', 'odden_quote_items');
        $products = config('odden-sales.tables.products', 'odden_deal_products');

        return [
            [$deals, 'lost_reason', 'deals.lost_reason', true],
            [$quotes, 'title', 'quotes.title', false],
            [$items, 'name', 'quote_items.name', false],
            [$items, 'description', 'quote_items.description', true],
            [$products, 'name', 'deal_products.name', false],
            [$products, 'description', 'deal_products.description', true],
            [config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules'), 'criteria', 'lead_routing_rules.criteria', true],
            [config('odden-sales.tables.automations', 'odden_stage_automations'), 'action_payload', 'stage_automations.action_payload', true],
        ];
    }
};
