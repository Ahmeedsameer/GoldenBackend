<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a payment method + optional transaction/reference number to each
 * invoice payment row so the sales system can distinguish Cash / Visa
 * (and, later, InstaPay, Bank Transfer, Vodafone Cash, …) payments.
 *
 * Backward compatible: existing rows default to 'cash' with a null
 * transaction number, so all historical invoices remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            // Stored as a plain string (not a DB enum) so new methods can be
            // added purely in application code without a schema migration.
            $table->string('payment_method', 30)
                  ->default('cash')
                  ->after('currency_id')
                  ->comment('cash | visa | (extensible: instapay, bank_transfer, vodafone_cash …)');

            // Reference/authorisation number — required only for card/electronic
            // methods (e.g. Visa). Nullable so cash payments are unaffected.
            $table->string('transaction_number', 100)
                  ->nullable()
                  ->after('payment_method')
                  ->comment('Visa/electronic transaction reference; null for cash');

            // Speeds up duplicate-reference lookups and payment-method reporting.
            // A plain (non-unique) index keeps multiple NULL cash rows valid;
            // duplicate-reference rejection is enforced at the request layer.
            $table->index('payment_method');
            $table->index('transaction_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['transaction_number']);
            $table->dropColumn(['payment_method', 'transaction_number']);
        });
    }
};
