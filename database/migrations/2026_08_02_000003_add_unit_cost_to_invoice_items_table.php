<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable snapshot of the consumed batch's purchase cost AT THE MOMENT
     * OF SALE — stored, not just derived live through goods->supplyItem, so
     * historical profit can never drift even if something upstream changes
     * later. line_cost/line_profit accessors prefer this column once
     * populated, falling back to the old live-join computation for any
     * pre-migration row that can't be backfilled.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
