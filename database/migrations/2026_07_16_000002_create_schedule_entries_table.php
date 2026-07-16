<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly schedule — one row per (employee, date). This is the PLAN (where/when
 * an employee should work), independent of Attendance (the ACTUAL record).
 *
 * Days covered by an active/scheduled/completed EmployeeTransfer are never
 * stored here — they are computed live from employee_transfers (the single
 * source of truth for "Transferred" cells) and are read-only in the UI.
 *
 * Purely additive: does not touch attendances, payrolls, invoices, or any
 * other existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            // work | leave | off_day | holiday | sick_leave | training
            // ('transferred' is never persisted — it is derived from employee_transfers)
            $table->string('type');
            $table->foreignId('shift_template_id')->nullable()->constrained('shift_templates')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
