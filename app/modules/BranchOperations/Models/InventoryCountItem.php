<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountItem extends Model
{
    protected $fillable = [
        'inventory_count_session_id', 'product_id', 'system_quantity', 'physical_quantity', 'difference', 'reason', 'counted_by',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:3',
        'physical_quantity' => 'decimal:3',
        'difference' => 'decimal:3',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventoryCountSession::class, 'inventory_count_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
