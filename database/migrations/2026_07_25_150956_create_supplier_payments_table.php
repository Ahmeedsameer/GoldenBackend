<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every payment belongs to exactly ONE purchase invoice (a `Supply` row) —
 * never a free-floating "pay this supplier X amount" — so each invoice's
 * remaining balance stays independently correct even when a supplier has
 * several open invoices. Money actually moves through the existing Safe
 * system (see SafeService::recordSupplierPayment() /
 * safe_transactions.supplier_payment_id) — this table is the purchase-side
 * record of WHICH invoice a payment reduced; SafeTransaction is the
 * accounting-side record of WHERE the cash came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies')->cascadeOnDelete();
            // Denormalized for fast supplier-level ledger queries without
            // joining through supplies — always equal to supplies.supplier_id.
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('safe_id')->constrained('safes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'date']);
            $table->index('supply_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
