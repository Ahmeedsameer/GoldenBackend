<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary Payment now moves real money through the Safe system (admin picks
 * which Safe pays the salary out) instead of `markPaid()` being a bare
 * status flip — same pattern already used for Salary Advances
 * (`salary_advances.paying_safe_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('paying_safe_id')->nullable()->after('status')->constrained('safes')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->after('paying_safe_id')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['paying_safe_id']);
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['paying_safe_id', 'paid_by', 'paid_at']);
        });
    }
};
