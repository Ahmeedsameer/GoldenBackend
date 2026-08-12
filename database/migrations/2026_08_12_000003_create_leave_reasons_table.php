<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable leave/attendance reasons. Each reason independently
 * controls two financial effects — deducts_leave_balance (consumes the
 * employee's monthly allowance) and deducts_salary (feeds the payroll
 * deduction engine using this reason's OWN mode/value, never the shared
 * hr_deduction_settings 'unpaid_leave' rate) — so the company can add new
 * reasons without any code change. No reason name is ever special-cased in
 * business logic; only these columns are read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('deducts_leave_balance')->default(true);
            $table->boolean('deducts_salary')->default(false);
            $table->string('deduction_mode')->nullable(); // daily_fraction | fixed — only meaningful when deducts_salary
            $table->decimal('deduction_value', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_reasons');
    }
};
