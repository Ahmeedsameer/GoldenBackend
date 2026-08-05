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
            $table->string('email')->nullable()->after('phone');
            $table->string('address')->nullable()->after('email');
            // Branch the customer is associated with (set from the creating
            // seller's active branch at first-purchase/quick-create time) —
            // customers themselves are not exclusively owned by one shop.
            $table->foreignId('shop_id')->nullable()->after('address')
                ->constrained('shops')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
            $table->dropColumn(['email', 'address']);
        });
    }
};
