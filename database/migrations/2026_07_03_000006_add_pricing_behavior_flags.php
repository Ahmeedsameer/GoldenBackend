<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the pricing/selling behavior explicit and type-driven (Product Type is
 * the single source of behavior), and adds per-gram pricing to oil categories.
 *
 *  - product_types.sold_by       : 'weight' (grams) | 'unit' (pieces) — the
 *                                  inventory/selling unit behavior.
 *  - product_types.pricing_source: 'category' (oil → price per gram lives on the
 *                                  category) | 'product' (non-oil → selling price
 *                                  lives on the product).
 *  - categories.price_per_gram   : oil price per gram (nullable; admin sets it).
 *                                  Non-oil categories leave it null.
 *
 * Backfill: oil → weight/category, every other type → unit/product. price_per_gram
 * stays null everywhere (coexistence: unconfigured categories keep using the
 * legacy global-total engine until an admin configures them). Fully additive;
 * existing data, invoices, FIFO and reports are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_types', function (Blueprint $table) {
            $table->string('sold_by', 10)->default('unit')->after('is_fixed')
                  ->comment('weight (grams) | unit (pieces)');
            $table->string('pricing_source', 10)->default('product')->after('sold_by')
                  ->comment('category (oil: price per gram) | product (non-oil: selling price)');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('price_per_gram', 12, 2)->nullable()->after('minimum_sell_price')
                  ->comment('Oil price per gram (nullable; admin-configured). New engine activates when set.');
        });

        // Behavior is oil vs non-oil: oil sells by weight and is priced on the
        // category; everything else sells by unit and is priced on the product.
        DB::table('product_types')->where('code', 'oil')
            ->update(['sold_by' => 'weight', 'pricing_source' => 'category']);
        DB::table('product_types')->where('code', '!=', 'oil')
            ->update(['sold_by' => 'unit', 'pricing_source' => 'product']);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('price_per_gram');
        });
        Schema::table('product_types', function (Blueprint $table) {
            $table->dropColumn(['sold_by', 'pricing_source']);
        });
    }
};
