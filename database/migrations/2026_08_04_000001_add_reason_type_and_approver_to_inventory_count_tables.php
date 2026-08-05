<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing Inventory Count architecture — never replaces it.
 * `inventory_count_items.reason` (free text) already existed and keeps
 * working exactly as before; `reason_type` is purely additive, a typed
 * category alongside it (see InventoryCountItem::REASON_TYPES), nullable so
 * every existing row and every existing API caller stays valid untouched.
 * `inventory_count_sessions.approved_at` already existed (timestamp only);
 * `approved_by` adds WHO approved, alongside it, same backward-compatible
 * pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_count_items', function (Blueprint $table) {
            $table->string('reason_type')->nullable()->after('reason');
        });

        Schema::table('inventory_count_sessions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_count_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });

        Schema::table('inventory_count_items', function (Blueprint $table) {
            $table->dropColumn('reason_type');
        });
    }
};
