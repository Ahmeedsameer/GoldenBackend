<?php


namespace App\Http\Services;

use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use Log;

class ProductService{

    public function create(CreateProductRequest $request){
        $data = $request->validated();
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = $image->store('products', 'public');
        }
        $data['image'] = $imagePath;
        $data['is_active'] = ($data['is_active'] === 'true' || $data['is_active'] === '1') ? true : false;
        if (isset($data['show_in_catalog'])) {
            $data['show_in_catalog'] = in_array($data['show_in_catalog'], ['true', '1', 1, true], true);
        }

        // SKU is auto-generated when the admin doesn't provide one — products are
        // found by barcode + name, so no manual SKU entry is required.
        if (empty($data['sku'])) {
            $data['sku'] = $this->generateUniqueSku();
        }

        // Inherit stock thresholds from the selected category's defaults when the
        // admin did not set them explicitly (still overridable per product later).
        $this->applyCategoryThresholdDefaults($data);

        // Enforce Product-Type-driven rules (unit from type; oil price on category).
        $this->applyProductTypeRules($data);

        $product = Product::create($data);

        return $product;
    }

    /**
     * Behavior is driven by the Product Type (never by category name):
     *  - the inventory unit is taken from the type's default_unit
     *    (oil → g, bottle/accessory/packaging → pcs) for Ready Products,
     *    Packaging and Compound, none of which offer their own unit choice;
     *  - Raw Materials are the one exception — their dedicated creation form
     *    (StoreRawMaterialRequest) lets the admin deliberately choose g/ml
     *    per the ERP spec (Alcohol=ml, everything else=g), so that choice
     *    must never be silently overridden by the category's default_unit
     *    here (categories like "كحول" share the same ProductType as
     *    piece-counted ready products, whose default_unit is 'pcs');
     *  - for oil (pricing_source = category) the selling price lives on the
     *    category, so any per-product selling_price is cleared.
     */
    private function applyProductTypeRules(array &$data): void
    {
        if (empty($data['category_id'])) {
            return;
        }
        $category = Category::with('productType')->find($data['category_id']);
        $type = $category?->productType;
        if (! $type) {
            return;
        }

        if ($type->default_unit && ($data['product_type'] ?? null) !== Product::TYPE_RAW_MATERIAL) {
            $data['scalar'] = $type->default_unit;
        }

        if ($type->pricing_source === 'category') {
            $data['selling_price'] = null; // oil price comes from the category
        }
    }

    /** Generate a unique auto SKU (e.g. PRD-3F9A2B7C). */
    private function generateUniqueSku(): string
    {
        do {
            $sku = 'PRD-' . strtoupper(bin2hex(random_bytes(4)));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * Fill missing per-product warning/critical thresholds from the category's
     * default_* values (if configured). Explicit product values always win.
     */
    private function applyCategoryThresholdDefaults(array &$data): void
    {
        if (empty($data['category_id'])) {
            return;
        }
        if (isset($data['warning_quantity']) && isset($data['critical_quantity'])) {
            return;
        }

        $category = Category::find($data['category_id']);
        if (! $category) {
            return;
        }

        if (! isset($data['warning_quantity']) && $category->default_warning_quantity !== null) {
            $data['warning_quantity'] = $category->default_warning_quantity;
        }
        if (! isset($data['critical_quantity']) && $category->default_critical_quantity !== null) {
            $data['critical_quantity'] = $category->default_critical_quantity;
        }
    }


        public function update(UpdateProductRequest $request,$id){
            $data = $request->validated();
            $product = Product::findOrFail($id);
            if ($request->hasFile('image')) {
             
                    if ($product->image) {
                    $previousImagePath = public_path('storage/' . $product->image);
                    if (file_exists($previousImagePath)) {
                        
                       
                            unlink($previousImagePath);
                    }
                }
                $image = $request->file('image');
                $imagePath = $image->store('products', 'public');
                $data['image'] = $imagePath;
            }
            if (isset($data['is_active'])) {
                $data['is_active'] = in_array($data['is_active'], ['true', '1', 1, true], true);
            }
            if (isset($data['show_in_catalog'])) {
                $data['show_in_catalog'] = in_array($data['show_in_catalog'], ['true', '1', 1, true], true);
            }

            // Keep Product-Type-driven rules consistent on update too. Uses the
            // incoming category_id when changed, else the product's current one.
            $data['category_id'] = $data['category_id'] ?? $product->category_id;
            $this->applyProductTypeRules($data);

            $product->update($data);
            Log::info('Product updated successfully', ['product_id' => $product->id, 'updated_data' => $data]);
            return $product;
    
        }


}