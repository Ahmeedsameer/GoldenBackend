<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convention_transactions', function (Blueprint $table) {
            $table->id();

            // the convention this spending belongs to
            $table->foreignId('convention_id')->constrained('conventions')->cascadeOnDelete();

            // the manager who recorded / spent the amount
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();

            // amount spent / recorded
            $table->decimal('amount', 12, 2);

            // the date of the spending
            $table->date('date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convention_transactions');
    }
};
