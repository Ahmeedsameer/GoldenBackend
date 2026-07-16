<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('bonus_total', 12, 2)->default(0)->after('branch_commission_amount');
            $table->decimal('penalty_total', 12, 2)->default(0)->after('bonus_total');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['bonus_total', 'penalty_total']);
        });
    }
};
