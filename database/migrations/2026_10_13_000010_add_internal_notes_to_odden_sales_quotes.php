<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A quote's `notes` are shown to the customer on the quote page (the editor in the Quotes resource calls them "Customer
     * Facing Notes"), but the Quotes tab of a deal labelled the same field "Internal Notes", so a rep's private remark could end up
     * on the page the customer opens. Notes a rep means to keep to themselves now have a column of their own that nothing
     * public renders.
     *
     * Existing `notes` are left where they are (they cannot be told apart): review those on quotes made from a deal's Quotes
     * tab before the customer opens them.
     */
    public function up(): void
    {
        if (Schema::hasColumn($this->table(), 'internal_notes')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('internal_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn($this->table(), 'internal_notes')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('internal_notes');
        });
    }

    private function table(): string
    {
        return config('odden-sales.tables.quotes', 'odden_quotes');
    }
};
