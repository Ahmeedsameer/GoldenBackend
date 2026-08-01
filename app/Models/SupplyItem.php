<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplyItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supply_id',
        'product_id',
        'quantity',
        'unit_price',
        // ── Batch pricing — set exactly once, ever (see PricingService::priceBatch()).
        // A batch with a null selling_price is invisible to FIFO sale consumption.
        'selling_price',
        'priced_at',
        'priced_by',
        // ── Archival — retires a batch from future FIFO sale consumption
        // without ever physically deleting it (see archive()). Historical
        // invoices must always be able to resolve their batch, so a batch is
        // NEVER hard-deleted once referenced by an invoice — only archived.
        'archived_at',
    ];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'unit_price'    => 'decimal:2',
        'selling_price' => 'decimal:2',
        'priced_at'     => 'datetime',
        'archived_at'   => 'datetime',
    ];

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function goods(): HasMany
    {
        return $this->hasMany(Goods::class);
    }

    public function pricedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priced_by');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /** Every invoice line ever sold from this exact batch — the direct, explicit
     *  reference deletion-protection relies on (see SupplyService::delete()). */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function isPriced(): bool
    {
        return $this->selling_price !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
