<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_id')->constrained('safes')->cascadeOnDelete();

            $table->enum('type', [
                'sale',
                'refund',
                'admin_deposit',
                'admin_withdrawal',
                'manager_deposit',
                'manager_expense',
            ]);

            // direction is always derived from type — stored for query convenience
            $table->enum('direction', ['in', 'out']);

            $table->decimal('amount', 12, 2);

            // linked to an invoice only for sale / refund types
            $table->foreignId('invoice_id')
                  ->nullable()
                  ->constrained('invoices')
                  ->nullOnDelete();

            $table->text('note')->nullable();

            // who performed the transaction
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_transactions');
    }
};
