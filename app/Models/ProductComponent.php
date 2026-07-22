<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductComponent extends Model
{
    protected $fillable = [
        'product_id',
        'component_product_id',
        'quantity',
        'is_variable_quantity',
        'component_group',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'is_variable_quantity' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
