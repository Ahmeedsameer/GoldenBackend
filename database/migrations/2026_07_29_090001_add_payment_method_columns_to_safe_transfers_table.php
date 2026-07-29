<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub Safes: one transfer feature serves both cross-branch transfers (existing,
 * unchanged when these are null) and same-branch child-safe transfers (new —
 * from_safe_id === to_safe_id is only allowed when both of these are set and differ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_transfers', function (Blueprint $table) {
            $table->foreignId('from_payment_method_id')->nullable()->after('to_safe_id')
                ->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('to_payment_method_id')->nullable()->after('from_payment_method_id')
                ->constrained('payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safe_transfers', function (Blueprint $table) {
            $table->dropForeign(['from_payment_method_id']);
            $table->dropForeign(['to_payment_method_id']);
            $table->dropColumn(['from_payment_method_id', 'to_payment_method_id']);
        });
    }
};
