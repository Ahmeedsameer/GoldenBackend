<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafeTransfer extends Model
{
    protected $fillable = [
        'from_safe_id',
        'to_safe_id',
        'currency_id',
        'amount',
        'note',
        'admin_id',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function fromSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'from_safe_id');
    }

    public function toSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'to_safe_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SafeTransaction::class, 'transfer_id');
    }
}
