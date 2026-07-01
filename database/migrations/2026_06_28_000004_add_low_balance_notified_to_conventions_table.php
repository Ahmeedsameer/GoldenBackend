<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conventions', function (Blueprint $table) {
            // tracks whether a low-balance alert has already been sent for the
            // current depletion cycle (reset when the balance rises above the threshold)
            $table->boolean('low_balance_notified')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('conventions', function (Blueprint $table) {
            $table->dropColumn('low_balance_notified');
        });
    }
};
