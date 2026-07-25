<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Management — a Supply row already IS a purchase invoice (one
 * supplier, dated, with line items); this widens it into a real one instead
 * of introducing a parallel "purchase_invoices" table. `payment_method`
 * (debt/immediate) is kept for backward compatibility but is no longer the
 * source of truth for payment state — `paid_amount` (updated by
 * SupplierPaymentService) is. Everything else (total, remaining, status) is
 * derived on the model (see Supply::getTotalAmountAttribute() etc.),
 * matching this codebase's existing "derived, never stored" convention
 * (SalaryAdvance::remaining_balance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->unique()->after('supplier_id');
            $table->decimal('discount', 12, 2)->default(0)->after('payment_method');
            $table->decimal('tax', 12, 2)->default(0)->after('discount');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'discount', 'tax', 'paid_amount']);
        });
    }
};
