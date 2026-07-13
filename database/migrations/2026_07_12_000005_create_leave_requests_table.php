<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — leave requests.
 *
 * When approved, the employee's remaining annual balance decreases. Days that
 * exceed the remaining balance are split off as unpaid_days, which the payroll
 * engine turns into unpaid-leave deductions. paid_days / unpaid_days are frozen
 * at approval time so later balance changes never rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days')->default(1);
            $table->string('type')->default('annual'); // annual | unpaid | sick
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('paid_days')->default(0);
            $table->unsignedSmallInteger('unpaid_days')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
