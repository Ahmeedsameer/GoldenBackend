<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave allowance is now MONTHLY (reset each month) instead of annual.
 * Renames the column and resets existing values to a sensible monthly default
 * (the old annual number would be nonsensical as a monthly quota). Admins set
 * the per-employee monthly allowance in the HR employee form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('annual_leave_allowance', 'monthly_leave_allowance');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('monthly_leave_allowance')->default(2)->change();
        });

        // Old values were annual (e.g. 21); reset to the monthly default.
        DB::table('users')->update(['monthly_leave_allowance' => 2]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('monthly_leave_allowance')->default(21)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('monthly_leave_allowance', 'annual_leave_allowance');
        });
    }
};
