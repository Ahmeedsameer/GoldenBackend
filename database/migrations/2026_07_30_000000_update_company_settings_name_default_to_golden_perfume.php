<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Golden Perfume is the locked, final production brand name (see
 * CompanySetting::current()) — the table's own column default must match so
 * a completely fresh deployment (schema created, no admin has visited Company
 * Settings yet) can never silently default to the old placeholder name. Raw
 * SQL (not Schema::table()->change()) — this project doesn't have
 * doctrine/dbal installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE company_settings MODIFY name VARCHAR(255) NOT NULL DEFAULT 'Golden Perfume'");

        // Any already-migrated environment still sitting on the placeholder
        // default (never customized via the admin form) gets corrected too.
        DB::table('company_settings')->where('name', 'Alpha Business')->update(['name' => 'Golden Perfume']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE company_settings MODIFY name VARCHAR(255) NOT NULL DEFAULT 'Alpha Business'");
    }
};
