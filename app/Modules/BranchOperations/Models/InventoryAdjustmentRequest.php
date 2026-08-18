<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory is never adjusted directly — every correction goes through
 * request -> review -> approval -> execution, each step logged with who and
 * when. Only `execute()` (InventoryAdjustmentService) ever touches Goods.
 */
class InventoryAdjustmentRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';

    protected $fillable = [
        'shop_id', 'product_id', 'before_quantity', 'after_quantity', 'difference', 'reason',
        'requested_by', 'status', 'reviewed_by', 'reviewed_at', 'executed_at', 'inventory_count_session_id',
    ];

    protected $casts = [
        'before_quantity' => 'decimal:3',
        'after_quantity' => 'decimal:3',
        'difference' => 'decimal:3',
        'reviewed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentBatch::class);
    }
}
