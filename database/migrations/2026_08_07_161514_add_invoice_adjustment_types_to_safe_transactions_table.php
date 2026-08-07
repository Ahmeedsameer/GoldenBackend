<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Edit Invoice workflow (SalesService::editInvoice()) — the cash difference
 * when an edited invoice's new total differs from its original total posts
 * as a new, distinct type (never reusing 'sale'/'refund', which both carry
 * their own specific meaning elsewhere) — same "widen the enum in place"
 * pattern as every other SafeTransaction type added so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment',
            'supplier_payment_refund','bank_charge','bank_charge_reversal','salary_payment',
            'invoice_adjustment_in','invoice_adjustment_out'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment',
            'supplier_payment_refund','bank_charge','bank_charge_reversal','salary_payment'
        ) NOT NULL");
    }
};
