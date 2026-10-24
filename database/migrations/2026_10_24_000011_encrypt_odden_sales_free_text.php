<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Odden\Core\Encryption\ColumnConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What people write about a deal and a quote (why it was lost, the notes on a quote) is encrypted. The columns are already
     * text. Amounts, stages and the lost reason picked from a list stay plain, so reports and filters keep working.
     */
    public function up(): void
    {
        if (! config('odden-core.encryption.convert_on_migrate', true)) {
            return;
        }

        $converter = app(ColumnConverter::class);

        $converter->encrypt(config('odden-sales.tables.deals', 'odden_deals'), 'lost_notes', 'deals.lost_notes');
        $converter->encrypt(config('odden-sales.tables.quotes', 'odden_quotes'), 'notes', 'quotes.notes');
        $converter->encrypt(config('odden-sales.tables.quotes', 'odden_quotes'), 'internal_notes', 'quotes.internal_notes');
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key.
     */
    public function down(): void
    {
        $converter = app(ColumnConverter::class);

        $converter->decrypt(config('odden-sales.tables.deals', 'odden_deals'), 'lost_notes', 'deals.lost_notes');
        $converter->decrypt(config('odden-sales.tables.quotes', 'odden_quotes'), 'notes', 'quotes.notes');
        $converter->decrypt(config('odden-sales.tables.quotes', 'odden_quotes'), 'internal_notes', 'quotes.internal_notes');
    }
};
