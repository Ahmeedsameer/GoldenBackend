<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional branch restriction for a payment method — no rows for a given
 * payment_method_id means unrestricted (available at every branch), matching
 * the existing "if no restriction exists, show all active methods" default.
 * Own id() PK + named FK/unique constraints, following this codebase's one
 * existing pivot-table precedent (inventory_count_session_employees) rather
 * than a bare composite-PK pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_shop', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unique(['payment_method_id', 'shop_id'], 'payment_method_shop_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_shop');
    }
};
