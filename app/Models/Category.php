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
        'is_fixed',
        'value_percentage',
    ];

    protected $casts = [
        'minimum_sell_price' => 'decimal:2',
        'is_fixed'           => 'boolean',
        'value_percentage'   => 'decimal:2',
    ];


        public function parent()
        {
            return $this->belongsTo(Category::class, 'parent_id');
        }
}
