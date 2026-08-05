<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('shop_id');
            $table->foreignId('notes_updated_by')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('notes_updated_at')->nullable()->after('notes_updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notes_updated_by');
            $table->dropColumn(['notes', 'notes_updated_at']);
        });
    }
};
