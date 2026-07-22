<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which exact Goods (FIFO) batches a waste record's quantity was drawn from —
 * mirrors transfer_request_item_batches (see TransferRequestItemBatch).
 * WasteService already does FIFO deduction across Goods rows; this makes
 * that consumption traceable back to Goods -> SupplyItem -> Supply ->
 * Supplier, enabling a real "Waste by Supplier" report instead of a
 * fabricated one — no new business concept, just recording an action the
 * service already performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_record_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waste_record_id')->constrained('waste_records')->cascadeOnDelete();
            $table->foreignId('goods_id')->constrained('goods')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_record_batches');
    }
};
