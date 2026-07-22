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
        Schema::table('invoice_items', function (Blueprint $table) {
            // Null (the default for every existing/normal row) = a plain,
            // standalone line exactly as today. Non-null groups this row under
            // a catalog product sold via the compose dialog, so the receipt/
            // invoice views can render one summarized line instead of N raw
            // component rows.
            $table->foreignId('parent_product_id')->nullable()->after('product_id')
                ->constrained('products')->nullOnDelete();

            // Which part of the recipe this row represents, for display only
            // (never affects pricing/FIFO): 'bottle' | 'oil' | null (fixed/
            // hidden component — cap, spray, box, label, … — or a normal line).
            $table->string('role')->nullable()->after('parent_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_product_id');
            $table->dropColumn('role');
        });
    }
};
