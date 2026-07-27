<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id',
        'shop_id',
        'branch_name',
        'seller_id',
        'seller_name',
        'seller_email',
        'tester_id',
        'date',
        'price_type',
        'status',
        'total_amount',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'date'         => 'date',
        'total_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tester_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * Cost/profit/fee summary — NOT auto-appended (no $appends) to avoid N+1 lazy
     * loads on endpoints that don't eager-load items/goods/supplyItem/payments.
     * Callers must eager-load those relations and explicitly ->append([...]) these
     * when the values are needed (see SalesService::getInvoicesForAdmin()).
     * Reuses InvoiceItem::line_cost and InvoicePayment::processing_fee_amount —
     * the exact same FIFO-cost/fee figures already shown on the invoice detail page.
     */
    public function getTotalCostAttribute(): float
    {
        return round((float) $this->items->sum('line_cost'), 2);
    }

    public function getGrossProfitAttribute(): float
    {
        return round((float) $this->total_amount - $this->total_cost, 2);
    }

    public function getBankFeeAttribute(): float
    {
        return round((float) $this->payments->sum('processing_fee_amount'), 2);
    }

    public function getNetProfitAttribute(): float
    {
        return round($this->gross_profit - $this->bank_fee, 2);
    }
}
