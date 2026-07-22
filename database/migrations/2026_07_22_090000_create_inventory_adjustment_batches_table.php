<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which exact Goods (FIFO) batches an executed adjustment touched — mirrors
 * waste_record_batches / transfer_request_item_batches. InventoryAdjustmentService::execute()
 * already resolves specific Goods rows to decrement (FIFO) or increment (latest batch);
 * this makes that action traceable back to Goods -> SupplyItem -> Supply -> Supplier,
 * enabling batch-level traceability (Phase 4.13) instead of a fabricated link.
 * quantity_delta is signed: positive for an increment, negative for a FIFO decrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_adjustment_request_id')
                ->constrained('inventory_adjustment_requests', 'id', 'iab_adjustment_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('goods_id')->constrained('goods')->restrictOnDelete();
            $table->decimal('quantity_delta', 12, 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_batches');
    }
};
