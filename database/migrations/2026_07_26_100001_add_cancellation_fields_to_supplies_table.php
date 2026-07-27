<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase invoices are cancelled, never hard-deleted, once money or
 * inventory may already be tied to them — mirrors TransferRequest's
 * cancelled_at/cancelled_by pair (no separate `status` column needed; a
 * non-null `cancelled_at` IS the cancelled state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('paid_amount');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
