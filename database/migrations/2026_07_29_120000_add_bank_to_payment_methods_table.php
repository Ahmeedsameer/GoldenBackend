<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Cards module: "Bank" (CIB, QNB, Banque Misr, HSBC, ...) is a distinct
 * attribute from a card's display `name` (e.g. name="Visa CIB", bank="CIB") —
 * only meaningful for card-type methods (PaymentMethod::CARD_TYPES), enforced
 * at the application layer like `processing_fee_percent` already is.
 * Reports that previously grouped by `name` as a stand-in for "bank"
 * (bankCharges()) now prefer this column, falling back to `name` for existing
 * rows created before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('bank')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('bank');
        });
    }
};
