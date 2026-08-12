<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens monthly_leave_allowance from an integer to a decimal so accrual
 * rates like 1.75 days/month are representable — every existing integer
 * value (e.g. 2) remains numerically identical under the new type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('monthly_leave_allowance', 6, 2)->default(2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('monthly_leave_allowance')->default(2)->change();
        });
    }
};
