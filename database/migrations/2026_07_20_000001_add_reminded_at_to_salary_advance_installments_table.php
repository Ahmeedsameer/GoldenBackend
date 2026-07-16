<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advance_installments', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('deducted_at');
        });

        // Defensive: rename any pre-existing 'deducted' rows to the new 'paid' terminal status.
        \Illuminate\Support\Facades\DB::table('salary_advance_installments')
            ->where('status', 'deducted')->update(['status' => 'paid']);
    }

    public function down(): void
    {
        Schema::table('salary_advance_installments', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
