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
            'is_fixed'            => (bool) $this->is_fixed,
            'value_percentage'    => $this->value_percentage,
            'parent'              => $this->whenLoaded('parent', function () {
                return new CategoryResource($this->parent);
            }),
        ];
    }
}
