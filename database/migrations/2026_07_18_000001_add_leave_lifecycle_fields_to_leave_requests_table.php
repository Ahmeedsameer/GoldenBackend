<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('original_end_date')->nullable()->after('end_date');
            $table->timestamp('ended_early_at')->nullable()->after('reviewed_at');
            $table->foreignId('ended_by')->nullable()->after('ended_early_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn(['original_end_date', 'ended_early_at']);
        });
    }
};
