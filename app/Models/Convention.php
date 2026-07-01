<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Convention extends Model
{
    protected $fillable = ['amount', 'low_balance_notified', 'admin_id', 'shop_id'];

    protected $casts = [
        'amount'               => 'decimal:2',
        'low_balance_notified' => 'boolean',
    ];

    // الأدمن المسؤول عن العهدة
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // الفرع الذي تخص العهدة
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    // حركات الصرف من العهدة
    public function transactions(): HasMany
    {
        return $this->hasMany(ConventionTransaction::class);
    }
}
