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
        ];
    }
}
