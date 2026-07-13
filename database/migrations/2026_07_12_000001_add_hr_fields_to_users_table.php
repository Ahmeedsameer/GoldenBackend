<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR & Payroll — employee profile fields on the existing users table.
 *
 * Employees ARE users (same JWT auth, same 3 roles). This adds the HR
 * profile: salary, personal commission, hire date, status, annual leave
 * allowance and free-text notes. All nullable / defaulted, so existing
 * users and authentication are completely unaffected (backward compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('base_salary', 12, 2)->default(0)->after('shop_id');
            $table->decimal('personal_commission_percent', 5, 2)->default(0)->after('base_salary');
            $table->date('hire_date')->nullable()->after('personal_commission_percent');
            $table->string('status')->default('active')->after('hire_date'); // active | inactive
            $table->unsignedSmallInteger('annual_leave_allowance')->default(21)->after('status');
            $table->text('hr_notes')->nullable()->after('annual_leave_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'base_salary',
                'personal_commission_percent',
                'hire_date',
                'status',
                'annual_leave_allowance',
                'hr_notes',
            ]);
        });
    }
};
