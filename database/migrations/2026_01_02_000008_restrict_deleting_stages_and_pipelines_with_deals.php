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
     * Deleting a pipeline or stage used to cascade to its deals (and from there to their quotes and products) and
     * to the stage history, bypassing soft deletes. These keys now refuse the delete instead.
     */
    public function up(): void
    {
        $this->setOnDelete('restrict');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setOnDelete('cascade');
    }

    private function setOnDelete(string $action): void
    {
        $pipelinesTable = config('odden-sales.tables.pipelines', 'odden_pipelines');
        $stagesTable = config('odden-sales.tables.stages', 'odden_pipeline_stages');
        $dealsTable = config('odden-sales.tables.deals', 'odden_deals');
        $historyTable = config('odden-sales.tables.stage_history', 'odden_deal_stage_history');

        Schema::table($dealsTable, function (Blueprint $table): void {
            $table->dropForeign(['pipeline_id']);
            $table->dropForeign(['stage_id']);
        });

        Schema::table($dealsTable, function (Blueprint $table) use ($pipelinesTable, $stagesTable, $action): void {
            $table->foreign('pipeline_id')->references('id')->on($pipelinesTable)->onDelete($action);
            $table->foreign('stage_id')->references('id')->on($stagesTable)->onDelete($action);
        });

        Schema::table($historyTable, function (Blueprint $table): void {
            $table->dropForeign(['to_stage_id']);
        });

        Schema::table($historyTable, function (Blueprint $table) use ($stagesTable, $action): void {
            $table->foreign('to_stage_id')->references('id')->on($stagesTable)->onDelete($action);
        });
    }
};
