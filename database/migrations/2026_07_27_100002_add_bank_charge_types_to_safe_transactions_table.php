<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A card/bank processing fee must participate in accounting as a real,
 * visible expense — not just a smaller credit. `bank_charge` (out) is debited
 * against the same safe right after the full gross `sale` is credited to it
 * (net balance impact identical to crediting net directly, but now with a
 * distinct, reportable ledger line). `bank_charge_reversal` (in) mirrors it
 * on cancellation, exactly like the `supplier_payment`/`supplier_payment_refund`
 * pair already established.
 *
 * `invoice_payment_id` disambiguates which exact payment LINE generated a
 * transaction — an invoice can have several lines hitting different safes,
 * so `invoice_id` alone is not enough for Safe History traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment',
            'supplier_payment_refund','bank_charge','bank_charge_reversal'
        ) NOT NULL");

        Schema::table('safe_transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->foreignId('invoice_payment_id')->nullable()->after('supplier_payment_id')
                ->constrained('invoice_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropForeign(['invoice_payment_id']);
            $table->dropColumn('invoice_payment_id');
        });

        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment',
            'supplier_payment_refund'
        ) NOT NULL");
    }
};
