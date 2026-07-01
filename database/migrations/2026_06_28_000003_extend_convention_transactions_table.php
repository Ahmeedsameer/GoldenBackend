<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) new audit / typing columns
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->enum('type', ['WITHDRAW', 'RECHARGE'])->default('WITHDRAW')->after('convention_id');
            $table->foreignId('admin_id')->nullable()->after('manager_id')->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable()->after('amount');
            $table->text('notes')->nullable()->after('reason');
            $table->softDeletes();
        });

        // 2) make manager_id nullable (entries not tied to a manager, e.g. admin-only records)
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->change();
        });
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable(false)->change();
        });
        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('convention_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_id');
            $table->dropColumn(['type', 'reason', 'notes']);
            $table->dropSoftDeletes();
        });
    }
};
