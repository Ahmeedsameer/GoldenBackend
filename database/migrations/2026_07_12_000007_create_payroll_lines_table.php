<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — immutable payroll breakdown lines.
 *
 * One row per component of a payroll (base salary, personal commission, one
 * branch-commission line per branch, one line per applied deduction). Earnings
 * are positive amounts, deductions are negative. This makes every payroll fully
 * auditable and is future-ready: bonuses / penalties / overtime are just new
 * line types with no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->string('type');  // base | personal_commission | branch_commission | deduction | bonus | penalty | overtime
            $table->string('label');
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete(); // for branch_commission lines
            $table->decimal('amount', 12, 2)->default(0); // + earning, - deduction
            $table->json('meta')->nullable(); // { percent, base_amount, days, ... }
            $table->timestamps();

            $table->index('payroll_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
