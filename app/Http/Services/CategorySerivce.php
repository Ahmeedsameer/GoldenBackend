<?php

namespace App\Http\Services;

use App\Models\Category;
use App\Models\ProductType;
use Illuminate\Support\Facades\Storage;


class CategorySerivce{

    public function createCategory( $request){
        $data = $request->validated();
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = $image->store('categories', 'public');
        }
        $data['image'] = $imagePath;
        $data = $this->applyTypeRules($data);
        $category = Category::create($data);

        return $category;
    }

    /**
     * The Product Type is the source of truth for category behavior:
     *  - is_fixed is taken from the type (never the client);
     *  - only category-priced types (oil) keep minimum_sell_price / price_per_gram;
     *    every other type ignores them (product-level pricing).
     * Behavior is driven by pricing_source, so future types need no changes here.
     */
    private function applyTypeRules(array $data): array
    {
        if (empty($data['product_type_id'])) {
            return $data;
        }
        $type = ProductType::find($data['product_type_id']);
        if (! $type) {
            return $data;
        }

        $data['is_fixed'] = (bool) $type->is_fixed;

        if ($type->pricing_source !== 'category') {
            $data['minimum_sell_price'] = 0;   // column is NOT NULL → 0 = not applicable
            $data['price_per_gram']     = null;
            $data['value_percentage']   = null;
        }

        return $data;
    }


    public function update($request){
        $data = $request->validated();
        $category = Category::findOrFail($request->id);
        if ($request->hasFile('image')) {
            #delet the previuse image 
                if ($category->image) {
                $previousImagePath = public_path('storage/' . $category->image);
                if (file_exists($previousImagePath)) {
                   
                    // Storage::delete($previousImagePath);
                        unlink($previousImagePath);
                }
            }
            $image = $request->file('image');
            $imagePath = $image->store('categories', 'public');
            $data['image'] = $imagePath;
        }
        // Re-apply type rules only when the type is part of this update.
        $data = $this->applyTypeRules($data);
        $category->update($data);

        return $category;

    }
}   