<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'description'         => $this->description,
            'image'               => $this->image ? asset('storage/' . $this->image) : null,
            'parent_id'           => $this->parent_id,
            'minimum_sell_price'  => $this->minimum_sell_price,
            'price_per_gram'      => $this->price_per_gram,
            'is_fixed'            => (bool) $this->is_fixed,
            'value_percentage'    => $this->value_percentage,
            'product_type_id'     => $this->product_type_id,
            'product_type'        => $this->whenLoaded('productType', function () {
                return [
                    'id'             => $this->productType->id,
                    'code'           => $this->productType->code,
                    'name'           => $this->productType->name,
                    'is_fixed'       => (bool) $this->productType->is_fixed,
                    'sold_by'        => $this->productType->sold_by,
                    'pricing_source' => $this->productType->pricing_source,
                ];
            }),
            'parent'              => $this->whenLoaded('parent', function () {
                return new CategoryResource($this->parent);
            }),
        ];
    }
}
