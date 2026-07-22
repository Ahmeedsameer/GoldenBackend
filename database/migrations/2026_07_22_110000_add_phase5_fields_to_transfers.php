<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.1 — three small, additive fields to support the redesigned
 * workflow WITHOUT touching the state machine or any existing column:
 *  - transfer_request_items.approved_quantity: lets the source-branch
 *    manager partially approve / modify a requested quantity at approval
 *    time. NULL = approved as requested (ship() falls back to
 *    requested_quantity), so every existing transfer keeps working exactly
 *    as before.
 *  - transfer_requests.is_emergency: flags admin-created emergency
 *    transfers (no approval step) for authorization/reporting — the status
 *    still flows through the SAME draft->submitted->approved->preparing->
 *    shipped states, just chained instantly server-side.
 *  - transfer_requests.cancelled_at/cancelled_by: admin override/cancel,
 *    reusing the existing 'rejected' terminal status rather than adding a
 *    new one — these columns just record that a rejection was an admin
 *    cancellation rather than a normal source-manager rejection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_request_items', function (Blueprint $table) {
            $table->decimal('approved_quantity', 12, 3)->nullable()->after('requested_quantity');
        });

        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->boolean('is_emergency')->default(false)->after('priority');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_request_items', function (Blueprint $table) {
            $table->dropColumn('approved_quantity');
        });

        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['is_emergency', 'cancelled_at']);
        });
    }
};
