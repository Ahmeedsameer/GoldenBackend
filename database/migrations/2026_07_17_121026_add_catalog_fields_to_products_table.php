<?php

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
        Schema::table('products', function (Blueprint $table) {
            // Lightweight categorization tag for the catalog/BOM UX layer only —
            // does NOT replace or interact with category->productType pricing
            // (is_fixed/sold_by/pricing_source), which stays fully unchanged.
            $table->string('product_type')->nullable()->after('category_id');

            // Default true so every existing product remains sellable exactly as
            // today (backward compatible) — admins explicitly hide raw materials
            // afterwards via the product form.
            $table->boolean('show_in_catalog')->default(true)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'show_in_catalog']);
        });
    }
};
