<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — immutable monthly payroll records.
 *
 * One frozen row per (employee, year, month). Salary and commission
 * percentages are SNAPSHOTTED here so that later changes to the employee's
 * salary/commission never alter historical payroll. The full breakdown lives
 * in payroll_lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1..12

            // Snapshots (immutable)
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('personal_commission_percent', 5, 2)->default(0);

            // Computed amounts
            $table->decimal('personal_sales_total', 14, 2)->default(0);
            $table->decimal('personal_commission_amount', 12, 2)->default(0);
            $table->decimal('branch_commission_amount', 12, 2)->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);

            // Attendance/leave context (informational snapshot)
            $table->unsignedSmallInteger('working_days')->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('late_days')->default(0);
            $table->unsignedSmallInteger('half_days')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);

            $table->string('status')->default('generated'); // generated | paid
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
