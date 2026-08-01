<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    use HasFactory;

    public const TYPE_COST_UPDATE  = 'cost_update';
    public const TYPE_PRICE_EDIT   = 'price_edit';
    /** A single Supply Batch (SupplyItem) received its (immutable, one-time) selling price. */
    public const TYPE_BATCH_PRICING = 'batch_pricing';

    protected $fillable = [
        'product_id',
        'supply_item_id',
        'old_cost',
        'new_cost',
        'old_selling_price',
        'new_selling_price',
        'type',
        'reason',
        'updated_by',
    ];

    protected $casts = [
        'old_cost'          => 'decimal:2',
        'new_cost'           => 'decimal:2',
        'old_selling_price'  => 'decimal:2',
        'new_selling_price'  => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
