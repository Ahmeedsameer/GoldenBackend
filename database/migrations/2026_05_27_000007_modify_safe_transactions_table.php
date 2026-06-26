<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('safe_transactions');

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
                'transfer_in',
                'transfer_out',
            ]);

            $table->enum('direction', ['in', 'out']);

            $table->foreignId('currency_id')
                  ->nullable()
                  ->constrained('currencies')
                  ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->foreignId('reason_id')
                  ->nullable()
                  ->constrained('transaction_reasons')
                  ->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignId('invoice_id')
                  ->nullable()
                  ->constrained('invoices')
                  ->nullOnDelete();

            $table->foreignId('transfer_id')
                  ->nullable()
                  ->constrained('safe_transfers')
                  ->nullOnDelete();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('safe_transactions');

        Schema::create('safe_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_id')->constrained('safes')->cascadeOnDelete();
            $table->enum('type', ['sale', 'refund', 'admin_deposit', 'admin_withdrawal', 'manager_deposit', 'manager_expense']);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 12, 2);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }
};
