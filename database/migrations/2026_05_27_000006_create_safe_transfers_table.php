<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_safe_id')->constrained('safes')->restrictOnDelete();
            $table->foreignId('to_safe_id')->constrained('safes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_transfers');
    }
};
