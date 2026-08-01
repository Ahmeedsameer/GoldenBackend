<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch-pricing events (type='batch_pricing') reference the specific
     * SupplyItem batch that was priced. Existing product-level rows
     * (cost_update/price_edit) simply leave this null — untouched.
     */
    public function up(): void
    {
        Schema::table('price_histories', function (Blueprint $table) {
            $table->foreignId('supply_item_id')->nullable()->after('product_id')
                ->constrained('supply_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_item_id');
        });
    }
};
