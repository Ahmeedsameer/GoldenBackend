<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number')->unique();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            // Admin who confirmed the settlement — nullable so a later admin-account
            // deactivation (or, in principle, deletion) never blocks on this FK.
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employment_status'); // resigned | terminated (frozen at settlement time)
            $table->date('leaving_date');
            $table->unsignedInteger('worked_days');
            $table->decimal('daily_rate', 12, 2);
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('salary_earned', 12, 2);
            $table->decimal('sales_commission', 12, 2)->default(0);
            $table->decimal('branch_profit', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('salary_advances', 12, 2)->default(0);
            $table->decimal('final_settlement', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_settlements');
    }
};
