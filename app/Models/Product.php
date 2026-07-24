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
        'product_type',
        'show_in_catalog',
        'capacity_ml',
        'default_selling_price',
        'priced_cost',
        'priced_at',
        'notes',
        'default_oil_id',
    ];

    /**
     * Product creation type — chosen once, up front, in the "what do you want
     * to create?" selector. Drives which fields the create/edit form shows
     * and how the product behaves in Sales:
     *  - RAW_MATERIAL: inventory-only (oils, alcohol, fixatives, …). Never in catalog.
     *  - PACKAGING: inventory-only bottles (capacity_ml set). Never in catalog.
     *  - COMPOUND: catalog-visible, NO inventory, NO predefined recipe — the
     *    seller freely picks any oil + quantity + bottle at sale time
     *    (see SalesService::calculateCompoundPrice).
     *  - READY_PRODUCT: catalog-visible, behaves exactly like a normal product
     *    (fixed price, own inventory) — today's pre-existing behavior.
     * Independent of category->productType (oil/bottle/accessory/packaging),
     * which still drives per-gram vs per-unit pricing and is untouched.
     */
    public const TYPE_RAW_MATERIAL  = 'RAW_MATERIAL';
    public const TYPE_PACKAGING     = 'PACKAGING';
    public const TYPE_COMPOUND      = 'COMPOUND';
    public const TYPE_READY_PRODUCT = 'READY_PRODUCT';

    public const PRODUCT_TYPES = [
        self::TYPE_RAW_MATERIAL,
        self::TYPE_PACKAGING,
        self::TYPE_COMPOUND,
        self::TYPE_READY_PRODUCT,
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'selling_price'     => 'decimal:2',
        'price_per_gram'    => 'decimal:2',
        'purchase_cost'     => 'decimal:2',
        'warning_quantity'  => 'decimal:3',
        'critical_quantity' => 'decimal:3',
        'show_in_catalog'   => 'boolean',
        'capacity_ml'       => 'decimal:2',
        'default_selling_price' => 'decimal:2',
        'priced_cost'           => 'decimal:2',
        'priced_at'             => 'datetime',
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
     * Phase 7 — Composite Products only: a preferred/default oil, used purely
     * to pre-select the oil dropdown in the Assemble-on-Sale dialog. NOT a
     * recipe — no quantity, no bottle/sprayer/cap is ever stored here, and
     * the cashier is always free to pick a different oil at sale time.
     */
    public function defaultOil()
    {
        return $this->belongsTo(Product::class, 'default_oil_id');
    }

    /** Pricing Management audit trail — every cost refresh / selling-price edit. */
    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class)->latest();
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
