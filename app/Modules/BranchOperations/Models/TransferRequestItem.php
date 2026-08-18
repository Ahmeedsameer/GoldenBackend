<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferRequestItem extends Model
{
    protected $fillable = [
        'transfer_request_id', 'product_id', 'unit',
        'available_stock_at_request', 'requested_quantity', 'approved_quantity',
        'received_quantity', 'missing_quantity', 'damaged_quantity', 'receiving_notes',
    ];

    protected $casts = [
        'available_stock_at_request' => 'decimal:3',
        'requested_quantity' => 'decimal:3',
        'approved_quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'missing_quantity' => 'decimal:3',
        'damaged_quantity' => 'decimal:3',
    ];

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(TransferRequestItemBatch::class);
    }
}
