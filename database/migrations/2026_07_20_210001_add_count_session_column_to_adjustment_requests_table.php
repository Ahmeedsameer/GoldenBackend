<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            // Plain nullable column for now — the FK to inventory_count_sessions
            // is added once that table exists (Part 8 migration).
            $table->unsignedBigInteger('inventory_count_session_id')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->dropColumn('inventory_count_session_id');
        });
    }
};
