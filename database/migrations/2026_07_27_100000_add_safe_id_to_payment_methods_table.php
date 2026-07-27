<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Methods Phase 2 — a payment method may be assigned a fixed Safe
 * (e.g. "Visa CIB" → the CIB Bank Safe, company-wide, regardless of which
 * branch made the sale). Null = no assignment; SalesService falls back to
 * today's shop-default-safe resolution exactly as before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('safe_id')->nullable()->after('currency_id')->constrained('safes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['safe_id']);
            $table->dropColumn('safe_id');
        });
    }
};
