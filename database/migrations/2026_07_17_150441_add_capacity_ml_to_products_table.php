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
            // Bottle capacity in ml — only meaningful for PACKAGING products.
            // Used to validate the seller's chosen oil quantity doesn't exceed
            // the selected bottle when composing a Compound Product sale.
            $table->decimal('capacity_ml', 10, 2)->nullable()->after('show_in_catalog');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('capacity_ml');
        });
    }
};
