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
            'scalar' => $this->scalar,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return new CategoryResource($this->category);
            }),
        ];
    }
}
