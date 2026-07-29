<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin-managed, unlimited payment methods (Cash EGP, Visa CIB, Vodafone
 * Cash, InstaPay, Fawry, ...) — the real source of truth for what a cashier
 * can select, replacing the old hardcoded 2-value
 * App\Modules\Sales\Enums\PaymentMethod (left in place, unused, for
 * historical string values on old invoice_payments rows).
 */
class PaymentMethod extends Model
{
    /** Types eligible for a card processing fee — the only types where `processing_fee_percent` is meaningful. */
    public const CARD_TYPES = ['visa', 'mastercard', 'bank_card'];

    public const TYPES = ['cash', 'visa', 'mastercard', 'bank_card', 'mobile_wallet', 'bank_transfer', 'other'];

    protected $fillable = ['name', 'bank', 'wallet_phone', 'type', 'currency_id', 'safe_id', 'processing_fee_percent', 'is_active'];

    protected $casts = [
        'processing_fee_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** The safe this method automatically credits — no manual safe pick at sale time. Null = fall back to the shop's default physical safe (SalesService::createInvoice()). */
    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /** Branches allowed to use this method. Empty = unrestricted (every active branch can use it). */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'payment_method_shop');
    }

    public function isCardType(): bool
    {
        return in_array($this->type, self::CARD_TYPES, true);
    }
}
