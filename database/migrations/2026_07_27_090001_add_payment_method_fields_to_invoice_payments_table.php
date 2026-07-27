<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links each payment line to the real admin-managed payment method it used,
 * and snapshots the fee actually applied at sale time (never recomputed
 * later even if the admin edits the method's fee % afterward). The old
 * `payment_method` string column is untouched — kept for backward
 * compatibility with historical rows and any code still reading it.
 *
 * `net_amount` is what the company actually receives after the processing
 * fee (Customer Paid 1000, fee 2% = 20, net 980) — this is the amount
 * credited to the Safe, NOT the gross `amount` — see SalesService::createInvoice().
 * The invoice's own total is never touched by any of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')
                ->constrained('payment_methods')->nullOnDelete();
            $table->decimal('processing_fee_percent', 5, 2)->default(0)->after('payment_method_id');
            $table->decimal('processing_fee_amount', 12, 2)->default(0)->after('processing_fee_percent');
            $table->decimal('net_amount', 12, 2)->nullable()->after('processing_fee_amount');
        });

        // Backfill: net_amount = amount for every existing row (no fee concept existed
        // before this migration, so 100% of what was recorded is what was received).
        DB::statement('UPDATE invoice_payments SET net_amount = amount WHERE net_amount IS NULL');

        // Best-effort link historical rows to the two seeded default methods by their
        // legacy string value, so old sales still show up correctly in the new reports.
        $cashId = DB::table('payment_methods')->where('type', 'cash')->value('id');
        $visaId = DB::table('payment_methods')->where('type', 'visa')->value('id');
        if ($cashId) DB::table('invoice_payments')->where('payment_method', 'cash')->update(['payment_method_id' => $cashId]);
        if ($visaId) DB::table('invoice_payments')->where('payment_method', 'visa')->update(['payment_method_id' => $visaId]);
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_id', 'processing_fee_percent', 'processing_fee_amount', 'net_amount']);
        });
    }
};
