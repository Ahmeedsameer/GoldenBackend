<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{

    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'image',
        'parent_id',
        'minimum_sell_price',
        'price_per_gram',
        'is_fixed',
        'value_percentage',
        'default_warning_quantity',
        'default_critical_quantity',
        'product_type_id',
    ];

    protected $casts = [
        'minimum_sell_price'        => 'decimal:2',
        'price_per_gram'            => 'decimal:2',
        'is_fixed'                  => 'boolean',
        'value_percentage'          => 'decimal:2',
        'default_warning_quantity'  => 'decimal:3',
        'default_critical_quantity' => 'decimal:3',
    ];


        public function parent()
        {
            return $this->belongsTo(Category::class, 'parent_id');
        }

        public function productType()
        {
            return $this->belongsTo(ProductType::class);
        }
}
