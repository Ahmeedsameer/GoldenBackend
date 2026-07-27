<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment against exactly ONE purchase invoice (Supply). The actual cash
 * movement (which Safe, balance update) is recorded separately as a
 * SafeTransaction (type='supplier_payment') via SafeService — this row is
 * the purchase-side record of which invoice it reduced; see
 * SupplierPaymentService::pay().
 */
class SupplierPayment extends Model
{
    protected $fillable = [
        'supply_id',
        'supplier_id',
        'safe_id',
        'currency_id',
        'amount',
        'date',
        'user_id',
        'note',
        'payment_method_id',
        'processing_fee_percent',
        'processing_fee_amount',
        'net_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'processing_fee_percent' => 'decimal:2',
        'processing_fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
