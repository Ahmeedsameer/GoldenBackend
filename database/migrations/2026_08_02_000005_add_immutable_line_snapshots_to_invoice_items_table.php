<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every InvoiceItem line becomes a fully self-contained, permanent accounting
 * record: the exact batch it was drawn from (supply_item_id — direct column,
 * not just derivable via goods_id -> goods.supply_item_id) plus its own
 * line_cost/line_profit, computed ONCE at sale time and never recalculated
 * from live product/batch data again. If a batch's price changes next week,
 * or the product is renamed/archived/deleted, these numbers must read back
 * identically forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('supply_item_id')->nullable()->after('goods_id')
                ->constrained('supply_items')->nullOnDelete();
            $table->decimal('line_cost', 12, 2)->nullable()->after('unit_cost');
            $table->decimal('line_profit', 12, 2)->nullable()->after('line_cost');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_item_id');
            $table->dropColumn(['line_cost', 'line_profit']);
        });
    }
};
