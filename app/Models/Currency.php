<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'symbol', 'rate', 'is_active'];

    protected $casts = [
        'rate'      => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function safeBalances(): HasMany
    {
        return $this->hasMany(SafeBalance::class);
    }

    public function safeTransactions(): HasMany
    {
        return $this->hasMany(SafeTransaction::class);
    }
}
