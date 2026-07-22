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
        Schema::table('product_components', function (Blueprint $table) {
            // true → the seller supplies the quantity at sale time (e.g. oil
            // grams); the saved `quantity` becomes just the default/suggested
            // starting value shown in the dialog. Default false preserves
            // today's fixed-quantity behavior for every existing recipe row.
            $table->boolean('is_variable_quantity')->default(false)->after('quantity');

            // Rows on the same parent sharing a non-null group are mutually-
            // exclusive alternatives the seller picks ONE of (e.g. all bottle
            // sizes tagged 'bottle'). Null = an always-included fixed component,
            // which is every existing row today (fully backward compatible).
            // A plain string (not an FK) so future groups (cap types, gift
            // packaging, …) need zero schema changes.
            $table->string('component_group')->nullable()->after('is_variable_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_components', function (Blueprint $table) {
            $table->dropColumn(['is_variable_quantity', 'component_group']);
        });
    }
};
