<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Supply row IS a purchase invoice (one supplier, dated, line items) —
 * `invoice_number`/`discount`/`tax`/`paid_amount` widen it into a real one
 * instead of a parallel "purchase_invoices" table. `paid_amount` is the one
 * running total actually stored (updated by SupplierPaymentService); total/
 * remaining/status are always derived here, never stored, so they can never
 * drift out of sync — the exact pattern already used by
 * SalaryAdvance::remaining_balance.
 */
class Supply extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'date',
        'payment_method',
        'invoice_number',
        'discount',
        'tax',
        'paid_amount',
    ];

    protected $casts = [
        'date' => 'date',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    protected $appends = ['items_subtotal', 'total_amount', 'remaining_amount', 'payment_status'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function getItemsSubtotalAttribute(): float
    {
        return (float) $this->items->sum(fn (SupplyItem $item) => $item->quantity * $item->unit_price);
    }

    public function getTotalAmountAttribute(): float
    {
        return round($this->items_subtotal - (float) $this->discount + (float) $this->tax, 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return round($this->total_amount - (float) $this->paid_amount, 2);
    }

    /** Paid / Partially Paid / Credit — derived purely from paid_amount vs total, never a stored flag. */
    public function getPaymentStatusAttribute(): string
    {
        if ((float) $this->paid_amount <= 0) {
            return 'credit';
        }
        return $this->remaining_amount <= 0 ? 'paid' : 'partial';
    }
}
