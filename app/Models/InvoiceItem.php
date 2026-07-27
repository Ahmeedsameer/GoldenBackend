<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'parent_product_id',
        'role',
        'goods_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price'    => 'decimal:2',
    ];

    protected $appends = ['unit_cost', 'line_cost', 'line_profit'];

    /** Real FIFO cost of the exact batch this line was sold from; falls back to the product's average purchase cost. */
    public function getUnitCostAttribute(): float
    {
        return (float) ($this->goods?->supplyItem?->unit_price ?? $this->product?->purchase_cost ?? 0);
    }

    public function getLineCostAttribute(): float
    {
        return round($this->unit_cost * (float) $this->quantity, 2);
    }

    public function getLineProfitAttribute(): float
    {
        return round(((float) $this->price * (float) $this->quantity) - $this->line_cost, 2);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The catalog product this component line was sold under, if any (compose flow). */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
