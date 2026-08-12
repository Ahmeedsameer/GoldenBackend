<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional link to an Admin-configured LeaveReason. Nullable and purely
 * additive — every existing/legacy LeaveRequest (reason_id = null) keeps
 * behaving exactly as before via the existing free-text `type` field and
 * the existing balance-overflow paid/unpaid split in LeaveService::approve().
 * Only requests that explicitly reference a reason use the new policy path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('reason_id')->nullable()->after('type')->constrained('leave_reasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reason_id');
        });
    }
};
