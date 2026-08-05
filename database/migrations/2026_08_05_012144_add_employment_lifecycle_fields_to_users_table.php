<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // working | resigned | terminated — gates whether the account (users.status)
            // may be deactivated; irrelevant for admin accounts (no employee lifecycle),
            // defaults to 'working' for everyone so existing rows are unaffected.
            $table->string('employment_status')->default('working')->after('status');
            $table->date('leaving_date')->nullable()->after('employment_status');
            $table->decimal('final_settlement_amount', 12, 2)->nullable()->after('leaving_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'leaving_date', 'final_settlement_amount']);
        });
    }
};
