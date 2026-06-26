<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: already present in the original create_categories_table migration
        if (Schema::hasColumn('categories', 'minimum_sell_price')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('minimum_sell_price', 10, 2)
                  ->default(0)
                  ->comment('الحد الأدنى لسعر البيع المسموح به لهذه الفئة');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('minimum_sell_price');
        });
    }
};
