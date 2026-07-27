<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.status` already has a 'cancelled' value, but it was only ever
 * used for the pending-approval reject path (no reversal of stock/money).
 * A real cancel (of an approved, already-fulfilled sale) needs its own
 * audit trail — mirrors TransferRequest's cancelled_at/cancelled_by pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
