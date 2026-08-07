<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product.current_display_price — a read-only performance CACHE, never a
 * source of truth. Every price-authoritative code path (cashier catalog,
 * sale processing, pricing screens) keeps resolving the live current FIFO
 * batch price exactly as before (SalesService::resolveConfiguredUnitPrice());
 * this column exists purely so list/search views that render many products'
 * prices at once (Product List, Pricing List) don't need one live FIFO join
 * per row. Represents the main warehouse's (shop_id = null) current FIFO
 * price — a single representative value for these shop-agnostic admin list
 * views, not any specific branch's authoritative live price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('current_display_price', 12, 2)->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('current_display_price');
        });
    }
};
