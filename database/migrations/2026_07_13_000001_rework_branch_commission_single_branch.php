<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-transfer architecture, part 1 — single active branch.
 *
 * The HR model is: every employee has exactly ONE primary branch
 * (users.shop_id) and one branch-commission ("branch bonus") percentage.
 * Employees are never simultaneously assigned to multiple branches; instead
 * they may be *temporarily transferred* (see employee_transfers).
 *
 * This drops the old multi-branch pivot and moves the branch commission % onto
 * the employee. (These structures were introduced earlier in the same HR build
 * and carry no production data.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_branches');

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('branch_commission_percent', 5, 2)->default(0)->after('personal_commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('branch_commission_percent');
        });

        Schema::create('employee_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->decimal('branch_commission_percent', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'shop_id']);
        });
    }
};
