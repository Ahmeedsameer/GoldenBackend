<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-transfer architecture, part 2 — temporary transfers.
 *
 * A transfer temporarily moves an employee from their primary branch to another
 * branch for a bounded period. Lifecycle:
 *   draft → (approve) → scheduled → (start_date) → active → (end_date) → completed
 *   cancel before activation → cancelled (no effect)
 *
 * The employee's ACTIVE branch on any date is derived from these rows:
 *   an effective transfer (not draft/cancelled) whose [start,end] covers the
 *   date ⇒ temporary_branch_id; otherwise ⇒ primary_branch_id (users.shop_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('primary_branch_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->foreignId('temporary_branch_id')->constrained('shops')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->string('status')->default('draft'); // draft|scheduled|active|completed|cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_transfers');
    }
};
