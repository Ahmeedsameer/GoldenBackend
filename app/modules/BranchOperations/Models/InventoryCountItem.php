<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountItem extends Model
{
    /** Typed category alongside the pre-existing free-text `reason` column —
     *  purely additive, never required, never replaces it. */
    public const REASON_TYPES = [
        'broken', 'damaged', 'theft', 'sale_not_recorded',
        'purchase_not_recorded', 'transfer_issue', 'counting_mistake', 'other',
    ];

    protected $fillable = [
        'inventory_count_session_id', 'product_id', 'system_quantity', 'physical_quantity', 'difference', 'reason', 'reason_type', 'counted_by',
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

    /** match | shortage | excess | pending (not yet counted) — single source of truth for this classification. */
    public function differenceStatus(): string
    {
        if ($this->physical_quantity === null) {
            return 'pending';
        }
        $diff = (float) $this->difference;
        if ($diff === 0.0) {
            return 'match';
        }
        return $diff > 0 ? 'excess' : 'shortage';
    }
}
