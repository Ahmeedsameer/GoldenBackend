<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6.2 — which Safe/Custody actually received this repayment, chosen by the admin at recording time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advance_repayments', function (Blueprint $table) {
            $table->foreignId('safe_id')
                ->nullable()->after('salary_advance_id')
                ->constrained('safes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_advance_repayments', function (Blueprint $table) {
            $table->dropForeign(['safe_id']);
            $table->dropColumn('safe_id');
        });
    }
};
