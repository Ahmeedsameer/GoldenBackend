<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last notified low-stock level per (product, shop) so inventory
 * notifications are only sent when the level actually changes — preventing
 * duplicate alerts for the same inventory state (Section #12 "avoid duplicate
 * notifications for the same inventory state").
 *
 * level: ok | warning | critical | out
 * shop_id NULL = main warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('level', 20)->default('ok')->comment('ok | warning | critical | out');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alert_states');
    }
};
