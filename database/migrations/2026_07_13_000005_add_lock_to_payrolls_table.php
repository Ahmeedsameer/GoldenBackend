<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll lock/unlock. A locked payroll is final: it cannot be regenerated or
 * deleted. Unlocking (admin only) re-allows regeneration. Immutability of
 * historical figures is preserved either way (regeneration replaces the row
 * only while unlocked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status');
            $table->foreignId('locked_by')->nullable()->after('is_locked')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'locked_by', 'locked_at']);
        });
    }
};
