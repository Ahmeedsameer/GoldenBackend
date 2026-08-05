<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'scalar' => $this->scalar,
            'is_active' => (bool) $this->is_active,
            'is_archived' => (bool) $this->is_archived,
            'archived_at' => $this->archived_at,
            'selling_price' => $this->selling_price,
            'price_per_gram' => $this->price_per_gram,
            'purchase_cost' => $this->purchase_cost,
            'profit' => $this->profit,
            'warning_quantity' => $this->warning_quantity,
            'critical_quantity' => $this->critical_quantity,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return new CategoryResource($this->category);
            }),
            // Previously missing here despite being fillable/stored — the product-list
            // edit form reads these directly off the list row, so without them every
            // edit silently mis-detected the product's creation type as READY_PRODUCT.
            'product_type' => $this->product_type,
            'capacity_ml' => $this->capacity_ml,
            'show_in_catalog' => (bool) $this->show_in_catalog,
            // Phase 7 — Default Oil convenience reference (Composite Products only).
            'default_oil_id' => $this->default_oil_id,
            'default_oil' => $this->whenLoaded('defaultOil', function () {
                return $this->defaultOil ? ['id' => $this->defaultOil->id, 'name' => $this->defaultOil->name] : null;
            }),
        ];
    }
}
