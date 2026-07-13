<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — daily attendance.
 *
 * One row per (employee, day). Default status is 'absent'; Admin / Branch
 * Manager flips it to present / late / half_day with a single click. The
 * unique (user_id, date) key makes marking idempotent (upsert per day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->date('date');
            $table->string('status')->default('absent'); // present | late | absent | half_day
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
