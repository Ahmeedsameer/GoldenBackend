<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Safe/Custody a repayment lands in by default — defaults to
 * `paying_safe_id` (the disbursement safe) at approval time, but can be
 * changed for FUTURE installments only via SalaryAdvanceService::changeDefaultSafe().
 * Past repayments/SafeTransactions are never touched by that change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->foreignId('default_repayment_safe_id')->nullable()->after('paying_safe_id')
                ->constrained('safes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropForeign(['default_repayment_safe_id']);
            $table->dropColumn('default_repayment_safe_id');
        });
    }
};
