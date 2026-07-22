<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Goods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which exact Goods (FIFO) batch an executed adjustment touched — mirrors
 * WasteRecordBatch / TransferRequestItemBatch. quantity_delta is signed:
 * positive for an increment (surplus), negative for a FIFO decrement (shortfall).
 */
class InventoryAdjustmentBatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['inventory_adjustment_request_id', 'goods_id', 'quantity_delta', 'created_at'];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function adjustmentRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustmentRequest::class, 'inventory_adjustment_request_id');
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
