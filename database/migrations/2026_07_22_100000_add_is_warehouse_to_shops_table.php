<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.1/5.2 — the Main Warehouse becomes a real Shop row so the
 * existing Transfer/Report/Dashboard architecture needs zero special-casing
 * for it (no nullable source_shop_id/destination_shop_id). is_warehouse
 * flags that one row; everything outside the inventory layer treats it as
 * an ordinary shop. Goods.shop_id = NULL still means "in the warehouse"
 * physically — WarehouseResolver is the only place that bridges the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_warehouse')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_warehouse');
        });
    }
};
