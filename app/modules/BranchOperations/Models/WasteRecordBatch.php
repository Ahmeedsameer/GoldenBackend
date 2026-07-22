<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Goods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which exact Goods (FIFO) batch a waste record's quantity was drawn from —
 * mirrors TransferRequestItemBatch. Enables Waste -> Goods -> SupplyItem ->
 * Supply -> Supplier traceability for the Waste by Supplier report.
 */
class WasteRecordBatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['waste_record_id', 'goods_id', 'quantity', 'created_at'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function wasteRecord(): BelongsTo
    {
        return $this->belongsTo(WasteRecord::class);
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
