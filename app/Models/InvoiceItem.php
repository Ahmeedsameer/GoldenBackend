<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'parent_product_id',
        'role',
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

    /** The catalog product this component line was sold under, if any (compose flow). */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function goods(): BelongsTo
    {
        return $this->belongsTo(Goods::class);
    }
}
