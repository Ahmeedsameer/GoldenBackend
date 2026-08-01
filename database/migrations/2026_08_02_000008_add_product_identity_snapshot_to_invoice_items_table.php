<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial values on invoice_items were already immutable (unit_cost,
 * price, line_cost, line_profit). Product IDENTITY was not — a renamed/
 * re-SKU'd product would silently change how an old invoice displays, even
 * though every number on it stayed correct. These three columns freeze the
 * display identity too, exactly like the financial snapshot: written once
 * at sale time, never touched again — see SalesService::processItem().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('product_sku')->nullable()->after('product_name');
            $table->string('product_barcode')->nullable()->after('product_sku');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'product_sku', 'product_barcode']);
        });
    }
};
