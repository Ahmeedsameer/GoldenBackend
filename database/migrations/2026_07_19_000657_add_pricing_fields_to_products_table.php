<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Compound Products only: the admin-set "starting" price the
            // Product Builder pre-fills at sale time. The seller can still
            // override it per invoice — the override never writes back here.
            $table->decimal('default_selling_price', 10, 2)->nullable()->after('selling_price');

            // Ready Products only: the cost snapshot as of the last time
            // Pricing Management confirmed it (via "Update Prices"). Compared
            // against the live latest purchase cost to flag "needs review".
            $table->decimal('priced_cost', 10, 2)->nullable()->after('purchase_cost');

            // Both types: when the price (selling_price for Ready /
            // default_selling_price for Compound) was last set by an admin.
            $table->timestamp('priced_at')->nullable()->after('priced_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['default_selling_price', 'priced_cost', 'priced_at']);
        });
    }
};
