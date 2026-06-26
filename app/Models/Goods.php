<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goods extends Model
{
    use HasFactory;

    protected $fillable = [
        'supply_item_id',
        'shop_id',
        'current_quantity',
        'date',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:3',
        'date'             => 'date',
    ];

    // NULL = المستودع الرئيسي
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
