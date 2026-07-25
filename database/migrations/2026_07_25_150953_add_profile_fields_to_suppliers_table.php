<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Management — the supplier profile grows from a bare name/phone
 * into a real profile (address, payout details) plus an opening balance for
 * suppliers that already had an outstanding debt before this system started
 * tracking payments (see SupplierPaymentService / Supply::remaining_amount).
 * All nullable: existing suppliers keep working with no backfill required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('address')->nullable()->after('phone');
            $table->string('bank_account_number')->nullable()->after('address');
            $table->string('mobile_wallet')->nullable()->after('bank_account_number');
            $table->string('instapay_iban')->nullable()->after('mobile_wallet');
            // A pre-existing debt owed to this supplier before onboarding onto
            // this system — a real stored constant (not derived from anything),
            // folded into the ledger's outstanding-balance calculation.
            $table->decimal('opening_balance', 12, 2)->default(0)->after('instapay_iban');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['address', 'bank_account_number', 'mobile_wallet', 'instapay_iban', 'opening_balance']);
        });
    }
};
