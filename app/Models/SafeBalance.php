<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeBalance extends Model
{
    protected $fillable = ['safe_id', 'currency_id', 'balance'];

    protected $casts = ['balance' => 'decimal:2'];

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
