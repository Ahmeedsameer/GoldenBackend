<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the last gap in the invoice identity snapshot: a composed sale's
 * oil/bottle/alcohol lines already freeze their OWN product_name, but the
 * catalog "parent" product they were grouped under (parent_product_id) had
 * no snapshot at all — the receipt/invoice display read it live via the
 * parent_product relation. Renaming that catalog product would silently
 * change how an old composed-sale receipt reads. See
 * SalesService::processItem() for where this is now frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('parent_product_name')->nullable()->after('parent_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('parent_product_name');
        });
    }
};
