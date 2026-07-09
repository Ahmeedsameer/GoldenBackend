<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Product Type defines inventory behavior (Oil, Bottle, Accessory, Packaging).
 * Categories classify products *within* a type (Oil → Oud/Musk/Amber).
 *
 * Behavior lives here (is_fixed pricing model, default unit, default thresholds)
 * so future inventory behaviors — BOM/recipes, refill containers, etc. — can be
 * added as new types without touching category classification or pricing rules.
 */
class ProductType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_fixed',
        'sold_by',
        'pricing_source',
        'default_unit',
        'default_warning_quantity',
        'default_critical_quantity',
    ];

    protected $casts = [
        'is_fixed'                  => 'boolean',
        'default_warning_quantity'  => 'decimal:3',
        'default_critical_quantity' => 'decimal:3',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
