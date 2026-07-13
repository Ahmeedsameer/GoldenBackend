<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch Bonus correction — the bonus percentage belongs to the BRANCH, not the
 * employee.
 *
 * Branch Bonus Pool = branch approved sales × branch bonus %.
 * The pool is then split EQUALLY among all employees actively assigned to the
 * branch during each sub-period (headcount changes as employees are transferred
 * in/out). Employees no longer carry a branch commission percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('branch_bonus_percent', 5, 2)->default(0)->after('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('branch_commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('branch_bonus_percent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('branch_commission_percent', 5, 2)->default(0)->after('personal_commission_percent');
        });
    }
};
