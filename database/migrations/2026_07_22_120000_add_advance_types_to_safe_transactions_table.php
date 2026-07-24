<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.1 — Salary Advances now move real money through the Safe system
 * (admin picks which Safe pays the advance / receives the repayment) instead
 * of an implicit, untracked "branch custody" deduction. This widens the
 * `type` ENUM in place (no drop/recreate — the table already holds real
 * transaction history from Sales/Transfers/Admin operations) and adds a
 * dedicated `salary_advance_id` FK, matching the existing one-FK-per-source
 * convention already used for `invoice_id`/`transfer_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out','advance_disbursement','advance_repayment'
        ) NOT NULL");

        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->foreignId('salary_advance_id')
                ->nullable()->after('transfer_id')
                ->constrained('salary_advances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transactions', function (Blueprint $table) {
            $table->dropForeign(['salary_advance_id']);
            $table->dropColumn('salary_advance_id');
        });

        DB::statement("ALTER TABLE safe_transactions MODIFY COLUMN type ENUM(
            'sale','refund','admin_deposit','admin_withdrawal','manager_deposit','manager_expense',
            'transfer_in','transfer_out'
        ) NOT NULL");
    }
};
