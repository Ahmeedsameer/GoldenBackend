<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Payments Phase 2 — reuse the same Payment Methods module instead
 * of a duplicate one. All nullable/optional: when `payment_method_id` is
 * omitted, SupplierPaymentService::pay() behaves exactly as before this
 * migration (explicit `safe_id`, no fee). Mirrors invoice_payments' fee
 * columns exactly, for the same historical-integrity reason (never
 * recomputed later even if the admin edits the method's fee % afterward).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('safe_id')
                ->constrained('payment_methods')->nullOnDelete();
            $table->decimal('processing_fee_percent', 5, 2)->default(0)->after('amount');
            $table->decimal('processing_fee_amount', 12, 2)->default(0)->after('processing_fee_percent');
            $table->decimal('net_amount', 12, 2)->nullable()->after('processing_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_id', 'processing_fee_percent', 'processing_fee_amount', 'net_amount']);
        });
    }
};
