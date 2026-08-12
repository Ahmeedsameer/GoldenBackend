<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave encashment — Admin converts part of an employee's accumulated
 * (carry-over) leave balance into money. Immutable once created: the days
 * are permanently removed from LeaveService::balance()'s cumulative
 * calculation (sum of leave_cash_outs.days is subtracted), so a cashed-out
 * day can never be cashed out again. The resulting amount is picked up by
 * PayrollService as a PayrollLine::LEAVE_ENCASHMENT earning for the month
 * this record's date falls in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_cash_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('days', 6, 2);
            $table->decimal('daily_rate', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_cash_outs');
    }
};
