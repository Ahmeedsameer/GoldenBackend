<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Safe extends Model
{
    protected $fillable = ['shop_id', 'safe_type_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function safeType(): BelongsTo
    {
        return $this->belongsTo(SafeType::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(SafeBalance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SafeTransaction::class);
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(SafeTransfer::class, 'from_safe_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(SafeTransfer::class, 'to_safe_id');
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPhysical(): bool
    {
        return $this->safeType?->kind === 'physical';
    }

    public function isCompanySafe(): bool
    {
        return $this->shop_id === null;
    }
}
