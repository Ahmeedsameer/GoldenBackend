<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advance_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained('salary_advances')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1..12
            $table->unsignedSmallInteger('sequence'); // 1-based order within the plan
            $table->decimal('planned_amount', 12, 2);
            $table->string('status')->default('pending'); // pending | deducted | skipped
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->timestamp('deducted_at')->nullable();
            $table->timestamps();

            $table->unique(['salary_advance_id', 'period_year', 'period_month'], 'adv_installment_period_unique');
            $table->index(['salary_advance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_installments');
    }
};
