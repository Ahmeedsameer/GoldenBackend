<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'goods_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price'    => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
