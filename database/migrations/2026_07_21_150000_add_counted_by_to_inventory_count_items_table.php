<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * InventoryCountService::recordCounts() already receives the acting User
 * but never persisted it per item — this makes "Employee Accuracy"
 * (Phase 4.9) real instead of guessed: which specific person recorded each
 * physical count, reusing the existing User entity (no new concept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_count_items', function (Blueprint $table) {
            $table->foreignId('counted_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_count_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counted_by');
        });
    }
};
