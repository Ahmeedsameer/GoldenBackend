<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Goods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which exact Goods (FIFO) batches a transfer item's shipped quantity was
 * drawn from — recorded at ship time so receiving can credit the destination
 * with the SAME supply_item_id per batch, preserving FIFO/cost lineage
 * exactly like TransferService::transfer() already does for instant transfers.
 */
class TransferRequestItemBatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'transfer_request_item_id', 'goods_id', 'quantity_shipped', 'quantity_received', 'created_at',
    ];

    protected $casts = [
        'quantity_shipped' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TransferRequestItem::class, 'transfer_request_item_id');
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
