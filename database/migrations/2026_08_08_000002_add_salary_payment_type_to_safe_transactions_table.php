<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary Payment now moves real money through the Safe system, mirroring
 * exactly how Salary Advance disbursement/repayment and Supplier Payment
 * were added before it (see 2026_07_22_120000_add_advance_types...,
 * 2026_07_26/27_...supplier_payment...). Widens the `type` ENUM in place
 * (re-listing every existing value — dropping one would reject writes of
 * that type) and adds a dedicated `payroll_id` FK, matching the existing
 * one-FK-per-source convention (`invoice_id`, `salary_advance_id`,
 * `supplier_payment_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment',
            'supplier_payment','supplier_payment_refund','bank_charge','bank_charge_reversal',
            'salary_payment'
        ) NOT NULL");

        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->foreignId('payroll_id')
                ->nullable()->after('supplier_payment_id')
                ->constrained('payrolls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->dropForeign(['payroll_id']);
            $table->dropColumn('payroll_id');
        });

        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment',
            'supplier_payment','supplier_payment_refund','bank_charge','bank_charge_reversal'
        ) NOT NULL");
    }
};
