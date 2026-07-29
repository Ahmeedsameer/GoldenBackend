<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub Safes (Payment Method Child Safes): a direct payment_method_id on every
 * SafeTransaction row is what makes a "child safe" writable — manual
 * deposits/withdrawals/transfers have no InvoicePayment to derive a payment
 * method from indirectly, so they need to set it themselves. Sales/refunds/
 * bank charges also start populating it directly at write time (in addition
 * to invoice_payment_id), so every NEW row is self-sufficient; reads still
 * fall back to invoice_payments.payment_method_id for historical rows.
 *
 * No change to safe_balances — the parent total stays the single source of
 * truth; child-safe balances remain derived from these rows, never duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('invoice_payment_id')
                ->constrained('payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });
    }
};
