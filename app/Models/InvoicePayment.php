<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    protected $fillable = [
        'invoice_id', 'safe_id', 'currency_id', 'amount', 'payment_method', 'transaction_number',
        'payment_method_id', 'processing_fee_percent', 'processing_fee_amount', 'net_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processing_fee_percent' => 'decimal:2',
        'processing_fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
