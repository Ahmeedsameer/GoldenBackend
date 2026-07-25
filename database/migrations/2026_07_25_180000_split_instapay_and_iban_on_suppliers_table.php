<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * InstaPay (mobile number) and IBAN (bank account number) are two distinct
 * payout methods — splitting the combined `instapay_iban` column into two.
 * Raw SQL rename (not Schema::renameColumn) to avoid a doctrine/dbal dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE suppliers CHANGE instapay_iban instapay VARCHAR(255) NULL');
        Schema::table('suppliers', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('iban')->nullable()->after('instapay');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn('iban');
        });
        DB::statement('ALTER TABLE suppliers CHANGE instapay instapay_iban VARCHAR(255) NULL');
    }
};
