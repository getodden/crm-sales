<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Sales\Models\Deal;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A deal is named after the person or company it is with, a signed quote records who signed it, and a booked meeting who
     * booked it, so those are stored encrypted. The columns become text, because the stored form is longer than the value. Deals are
     * found by name through the search index (see Odden\Core\Encryption\Index\SearchIndex), which is built from the rows already stored.
     */
    public function up(): void
    {
        foreach ($this->columns() as [$table, $column, $field]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->text($column)->nullable($column !== 'name')->change();
            });
        }

        if (! config('odden-core.encryption.convert_on_migrate', true)) {
            return;
        }

        $converter = app(ColumnConverter::class);

        foreach ($this->columns() as [$table, $column, $field]) {
            $converter->encrypt($table, $column, $field);
        }

        app(SearchIndex::class)->rebuild(Deal::class);
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first, and the deals' index rows go.
     */
    public function down(): void
    {
        $converter = app(ColumnConverter::class);

        foreach ($this->columns() as [$table, $column, $field]) {
            $converter->decrypt($table, $column, $field);
        }

        if (Schema::hasTable($index = config('odden-core.tables.search_index', 'odden_search_index'))) {
            DB::table($index)->where('field', 'like', 'deals.%')->delete();
        }
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function columns(): array
    {
        $deals = config('odden-sales.tables.deals', 'odden_deals');
        $quotes = config('odden-sales.tables.quotes', 'odden_quotes');
        $bookings = config('odden-sales.tables.meeting_bookings', 'odden_sales_meeting_bookings');

        return [
            [$deals, 'name', 'deals.name'],
            [$quotes, 'signed_by_name', 'quotes.signed_by_name'],
            [$quotes, 'signed_by_email', 'quotes.signed_by_email'],
            [$bookings, 'invitee_name', 'meeting_bookings.invitee_name'],
            [$bookings, 'invitee_email', 'meeting_bookings.invitee_email'],
        ];
    }
};
