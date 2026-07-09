<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill of Materials (BOM) / recipes.
 *
 * A composed product (e.g. a ready perfume) is made of component products
 * (oil grams + a bottle + accessories). When it is composed/sold, each
 * component deducts its own stock. This table stores the saved recipe:
 * one row per (parent product → component product, quantity).
 *
 * Additive; existing products/invoices are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();          // parent (perfume)
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete(); // component
            $table->decimal('quantity', 12, 3)->comment('Component quantity per 1 unit of the parent');
            $table->timestamps();

            $table->unique(['product_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_components');
    }
};
