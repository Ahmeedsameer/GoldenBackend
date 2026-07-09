<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfume management foundation (Phase 1).
 *
 * Adds, backward-compatibly (all nullable, no data touched):
 *  - products.selling_price      : per-product selling price (admin configurable);
 *                                  the category minimum_sell_price remains the floor.
 *  - products.warning_quantity   : per-product low-stock warning threshold.
 *  - products.critical_quantity  : per-product critical-stock threshold.
 *  - categories.default_warning_quantity  : default inherited by new products.
 *  - categories.default_critical_quantity : default inherited by new products.
 *
 * Existing products/invoices/inventory are unaffected — every column is nullable
 * and read paths treat null as "not configured".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('selling_price', 12, 2)
                  ->nullable()
                  ->after('scalar')
                  ->comment('Per-product selling price; category minimum_sell_price is the floor');

            $table->decimal('warning_quantity', 12, 3)
                  ->nullable()
                  ->after('selling_price')
                  ->comment('Low-stock warning threshold (in the product unit)');

            $table->decimal('critical_quantity', 12, 3)
                  ->nullable()
                  ->after('warning_quantity')
                  ->comment('Critical-stock threshold (in the product unit)');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('default_warning_quantity', 12, 3)
                  ->nullable()
                  ->after('value_percentage')
                  ->comment('Default warning threshold inherited by new products of this type');

            $table->decimal('default_critical_quantity', 12, 3)
                  ->nullable()
                  ->after('default_warning_quantity')
                  ->comment('Default critical threshold inherited by new products of this type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['selling_price', 'warning_quantity', 'critical_quantity']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['default_warning_quantity', 'default_critical_quantity']);
        });
    }
};
