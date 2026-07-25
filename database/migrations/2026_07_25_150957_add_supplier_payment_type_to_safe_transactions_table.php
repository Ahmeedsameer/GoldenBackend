<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Management — paying a supplier moves real money through the Safe
 * system (admin picks which Safe: Main Safe, a branch's safe, or any other
 * active safe), exactly like Salary Advance disbursements. Widens the `type`
 * ENUM in place (no drop/recreate — matches 2026_07_22_120000's convention)
 * and adds a dedicated `supplier_payment_id` FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment','supplier_payment'
        ) NOT NULL");

        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->foreignId('supplier_payment_id')
                ->nullable()->after('salary_advance_id')
                ->constrained('supplier_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->dropForeign(['supplier_payment_id']);
            $table->dropColumn('supplier_payment_id');
        });

        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment'
        ) NOT NULL");
    }
};
