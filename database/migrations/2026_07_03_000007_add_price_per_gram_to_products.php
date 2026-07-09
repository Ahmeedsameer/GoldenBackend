<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the oil per-gram price from the Category to the Product.
 *
 *  - Oil pricing now lives on each product: qty (g) × products.price_per_gram.
 *  - The Category keeps minimum_sell_price as the floor (unchanged).
 *  - categories.price_per_gram column is kept in place for backward compatibility
 *    (the resolver falls back to it when a product's own price isn't set yet),
 *    so historical data and legacy configurations continue to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price_per_gram', 12, 2)
                  ->nullable()
                  ->after('selling_price')
                  ->comment('Per-product price per gram (for oil-type products); admin-configured');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_per_gram');
        });
    }
};
