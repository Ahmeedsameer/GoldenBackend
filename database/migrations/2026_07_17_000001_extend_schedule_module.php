<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — extends the Weekly Schedule module (additive only):
 *   - shift_templates: +color, +description (optional presets, admin managed).
 *   - schedule_entries: +start_time/end_time (manual hours — the DEFAULT way
 *     to set a work day; shift_template_id remains an optional shortcut that
 *     can prefill these on the client).
 *
 * No existing column is touched; fully backward compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_templates', function (Blueprint $table) {
            $table->string('color')->default('blue')->after('name');
            $table->text('description')->nullable()->after('end_time');
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('shift_template_id');
            $table->time('end_time')->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });

        Schema::table('shift_templates', function (Blueprint $table) {
            $table->dropColumn(['color', 'description']);
        });
    }
};
