<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_id')->constrained('safes')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['safe_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_balances');
    }
};
