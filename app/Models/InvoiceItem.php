<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        // Permanent DISPLAY snapshot — the product's name/sku/barcode exactly
        // as they were at sale time, set ONCE in SalesService::processItem()
        // and never touched again. A later rename/re-SKU of the product must
        // never change how an old invoice reads. See getProductNameAttribute()
        // etc. for the legacy-row (pre-this-column) fallback only.
        'product_name',
        'product_sku',
        'product_barcode',
        'parent_product_id',
        // Same treatment for the catalog "parent" a composed oil/bottle/
        // alcohol line was sold under — frozen once, never re-read live.
        'parent_product_name',
        'role',
        'goods_id',
        // Permanent accounting snapshot — the exact batch this line was drawn
        // from, its cost, and its computed line profit, all set ONCE at sale
        // time in SalesService::processItem() and never recalculated
        // afterward. See getLineCostAttribute()/getLineProfitAttribute() for
        // the legacy-row (pre-this-column) fallback only.
        'supply_item_id',
        'quantity',
        'price',
        'unit_cost',
        'line_cost',
        'line_profit',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'price'       => 'decimal:2',
        'unit_cost'   => 'decimal:2',
        'line_cost'   => 'decimal:2',
        'line_profit' => 'decimal:2',
    ];

    protected $appends = ['line_cost', 'line_profit'];

    /**
     * Real FIFO cost of the exact batch this line was sold from — a stored,
     * immutable snapshot for every row created after the batch-pricing
     * migration. Rows created before it (no snapshot) fall back to the live
     * goods->supplyItem join, then the product's average purchase cost,
     * exactly as before — so old invoices keep working unchanged.
     */
    public function getUnitCostAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        return (float) ($this->goods?->supplyItem?->unit_price ?? $this->product?->purchase_cost ?? 0);
    }

    /**
     * Permanent accounting snapshot, set once at sale time — NEVER recomputed
     * from live data on read. Only a legacy row from before this column
     * existed (line_cost is null) falls back to a live derivation, purely so
     * pre-migration invoices keep displaying a number instead of nothing.
     */
    public function getLineCostAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        return round($this->unit_cost * (float) $this->quantity, 2);
    }

    public function getLineProfitAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        return round(((float) $this->price * (float) $this->quantity) - $this->line_cost, 2);
    }

    /**
     * Permanent display snapshot — the product's name exactly as it was at
     * sale time. NEVER re-read from the live product on read. Only a legacy
     * row from before this column existed falls back to the live relation,
     * purely so pre-migration invoices keep displaying a name instead of
     * nothing — a rename after that point does NOT retroactively change it,
     * since the backfill migration already froze it in for every such row.
     */
    public function getProductNameAttribute($value): ?string
    {
        return $value ?? $this->product?->name;
    }

    public function getProductSkuAttribute($value): ?string
    {
        return $value ?? $this->product?->sku;
    }

    public function getProductBarcodeAttribute($value): ?string
    {
        return $value ?? $this->product?->barcode;
    }

    /** Same frozen-once treatment for the catalog "parent" a composed line was grouped under. */
    public function getParentProductNameAttribute($value): ?string
    {
        return $value ?? $this->parentProduct?->name;
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

    /** The exact purchase batch this line was drawn from — permanent, never re-derived. */
    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class);
    }
}
