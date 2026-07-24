<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — Default Oil convenience field for Composite Products. This is
 * NOT a recipe/BOM: it stores a single preferred Raw Material (oil) reference
 * used only to pre-select the oil dropdown when a cashier opens the
 * Assemble-on-Sale composition dialog — no quantities, no bottle/sprayer/cap,
 * nothing else is ever stored here or auto-filled from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('default_oil_id')
                ->nullable()->after('category_id')
                ->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['default_oil_id']);
            $table->dropColumn('default_oil_id');
        });
    }
};
