<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $enrollmentsTable = config('odden-sales.tables.sequence_enrollments', 'odden_sales_sequence_enrollments');

        // When the current run of the cadence began. Enrolling the same contact in the same sequence again re-uses
        // the row, so this is what tells a new run from the steps done in an earlier one.
        Schema::table($enrollmentsTable, function (Blueprint $table): void {
            $table->timestamp('enrolled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $enrollmentsTable = config('odden-sales.tables.sequence_enrollments', 'odden_sales_sequence_enrollments');

        Schema::table($enrollmentsTable, function (Blueprint $table): void {
            $table->dropColumn('enrolled_at');
        });
    }
};
