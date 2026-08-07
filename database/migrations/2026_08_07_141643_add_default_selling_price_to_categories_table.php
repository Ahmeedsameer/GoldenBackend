<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category.default_selling_price — NOT the real selling price of anything
 * (that always lives on the batch/SupplyItem, or on the product for legacy
 * non-batch types). Purely a convenience: auto-fills a new batch's price
 * when it's first priced, and (together with the existing minimum_sell_price
 * floor) validates that a batch price is never set below it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('default_selling_price', 12, 2)->nullable()->after('minimum_sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('default_selling_price');
        });
    }
};
