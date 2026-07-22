<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_request_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 12, 3)->nullable()->after('requested_quantity');
            $table->decimal('missing_quantity', 12, 3)->nullable()->after('received_quantity');
            $table->decimal('damaged_quantity', 12, 3)->nullable()->after('missing_quantity');
            $table->text('receiving_notes')->nullable()->after('damaged_quantity');
        });

        Schema::create('transfer_request_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_request_item_id')->constrained('transfer_request_items')->cascadeOnDelete();
            $table->foreignId('goods_id')->constrained('goods')->restrictOnDelete();
            $table->decimal('quantity_shipped', 12, 3);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_request_item_batches');
        Schema::table('transfer_request_items', function (Blueprint $table) {
            $table->dropColumn(['received_quantity', 'missing_quantity', 'damaged_quantity', 'receiving_notes']);
        });
    }
};
