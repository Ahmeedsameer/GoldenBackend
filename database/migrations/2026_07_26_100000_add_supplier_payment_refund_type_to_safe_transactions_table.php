<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a purchase invoice that was already (partially) paid must credit
 * the money back into the exact safe/currency it came from — an INFLOW,
 * unlike `supplier_payment` (outflow). `refund` already exists but is
 * direction 'out' (used for sales refunds), so a distinct type is needed
 * rather than reusing it. Links back via the existing `supplier_payment_id`
 * FK — no new column needed, just widening the enum in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment',
            'supplier_payment_refund'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment'
        ) NOT NULL");
    }
};
