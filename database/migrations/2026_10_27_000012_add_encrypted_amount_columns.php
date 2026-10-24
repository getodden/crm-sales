<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\BusinessDataConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A workspace can choose to keep deal amounts and sales targets encrypted ("Encrypt business data"). The figure then lives in
     * the text column beside the decimal one, which is left at 0.00. Nothing is converted here: the setting is off until someone
     * turns it on, and `odden:convert-business-data` moves the stored figures.
     */
    public function up(): void
    {
        $this->add(config('odden-sales.tables.deals', 'odden_deals'), 'amount');
        $this->add(config('odden-sales.tables.quotas', 'odden_sales_quotas'), 'target_amount');
    }

    /**
     * Reverse the migrations. Encrypted figures are moved back to the plain columns first, so no figure is lost.
     */
    public function down(): void
    {
        app(BusinessDataConverter::class)->convert(false);

        foreach ([[config('odden-sales.tables.deals', 'odden_deals'), 'amount'], [config('odden-sales.tables.quotas', 'odden_sales_quotas'), 'target_amount']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column.'_encrypted')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column.'_encrypted'));
            }
        }
    }

    private function add(string $table, string $column): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column.'_encrypted')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text($column.'_encrypted')->nullable()->after($column));
        }
    }
};
