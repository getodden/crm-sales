<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'odden_sales_enrollments_sequence_contact_unique';

    /**
     * Run the migrations.
     *
     * A contact has one enrollment per sequence (enrolling again re-uses the row), but nothing enforced it, so two
     * enrollments made at the same moment could both send every step. Existing duplicates are removed first.
     */
    public function up(): void
    {
        $this->removeDuplicates();

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->unique(['sequence_id', 'contact_id'], self::INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * Keep one enrollment per sequence and contact, chosen as when contacts are merged: a finished or stopped
     * enrollment wins over an active one, so outreach is never restarted; otherwise the further-along one, then
     * the older one.
     */
    public function removeDuplicates(): void
    {
        $table = $this->table();

        $pairs = DB::table($table)
            ->select(['sequence_id', 'contact_id'])
            ->groupBy('sequence_id', 'contact_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($pairs as $pair) {
            $ids = DB::table($table)
                ->where('sequence_id', $pair->sequence_id)
                ->where('contact_id', $pair->contact_id)
                ->orderByRaw("CASE WHEN status = 'active' THEN 1 ELSE 0 END")
                ->orderByDesc('current_step')
                ->orderBy('id')
                ->pluck('id');

            DB::table($table)->whereIn('id', $ids->slice(1)->all())->delete();
        }
    }

    private function table(): string
    {
        return config('odden-sales.tables.sequence_enrollments', 'odden_sales_sequence_enrollments');
    }
};
