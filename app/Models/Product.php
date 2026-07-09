<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'image',
        'sku',
        'barcode',
        'description',
        'is_active',
        'scalar',
        'category_id',
        'selling_price',
        'price_per_gram',
        'purchase_cost',
        'warning_quantity',
        'critical_quantity',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'selling_price'     => 'decimal:2',
        'price_per_gram'    => 'decimal:2',
        'purchase_cost'     => 'decimal:2',
        'warning_quantity'  => 'decimal:3',
        'critical_quantity' => 'decimal:3',
    ];

    protected $appends = ['profit'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /** BOM components (recipe) — this product is composed of these. */
    public function components()
    {
        return $this->hasMany(ProductComponent::class, 'product_id');
    }

    /**
     * Single source of truth for product text search, reused everywhere a
     * product is searched (Supply, Transfer, Product selection, Cashier).
     *
     * Matches — case-insensitively (via the utf8mb4_*_ci collation) and
     * partially — on product name, SKU or barcode. Apply it to a product query
     * directly (`Product::search($t)`) or inside a relation
     * (`whereHas('...product', fn ($q) => $q->search($t))`).
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('sku', 'like', "%{$term}%")
              ->orWhere('barcode', 'like', "%{$term}%");
        });
    }

    /**
     * Unit profit = selling_price − purchase_cost.
     * Null when either side is not configured (avoids misleading 0 profit).
     */
    public function getProfitAttribute(): ?float
    {
        if ($this->selling_price === null || $this->purchase_cost === null) {
            return null;
        }
        return round((float) $this->selling_price - (float) $this->purchase_cost, 2);
    }
}
