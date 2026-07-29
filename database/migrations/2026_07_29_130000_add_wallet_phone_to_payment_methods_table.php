<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile wallet payment methods (Vodafone Cash, Orange Cash, InstaPay, ...)
 * need the wallet's phone number captured at creation time — only meaningful
 * when `type` = 'mobile_wallet', same "server never trusts the client to
 * have hidden the field correctly" pattern as `bank`/`processing_fee_percent`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('wallet_phone')->nullable()->after('bank');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('wallet_phone');
        });
    }
};
