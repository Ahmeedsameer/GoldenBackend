<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A SupplyItem is already exactly "one row per product per purchase
     * batch" — the natural home for per-batch selling price, matching
     * Batch #1 / Batch #2 in the pricing spec, without a new table.
     */
    public function up(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->decimal('selling_price', 12, 2)->nullable()->after('unit_price');
            $table->timestamp('priced_at')->nullable()->after('selling_price');
            $table->foreignId('priced_by')->nullable()->after('priced_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('priced_by');
            $table->dropColumn(['selling_price', 'priced_at']);
        });
    }
};
