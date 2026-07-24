<?php

namespace App\Http\Requests;

use App\Models\Product;

/**
 * Compound Product (a perfume composed fresh at sale time — Oil + Bottle,
 * chosen by the cashier in the Product Builder). Virtual: absolutely no
 * inventory (no scalar/warning/critical/stock/warehouse/purchase-cost
 * fields), no selling price on this form — the Builder's default price is
 * set exclusively via Pricing Management's default_selling_price, never at
 * creation time. Only an optional `default_oil_id` pre-selection is unique
 * to this type.
 */
class StoreCompoundRequest extends CreateProductRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_type'    => Product::TYPE_COMPOUND,
            // Only applied when the request omits it entirely; the frontend
            // form always sends its own value. Merged as a string ('1'), not
            // a PHP bool, since Laravel's `in:` rule casts to string first.
            'show_in_catalog' => $this->has('show_in_catalog') ? $this->input('show_in_catalog') : '1',
            // Never used for a Compound — set purely to satisfy the column's
            // NOT NULL default; nothing downstream reads it for this type.
            'scalar'          => 'pcs',
            'is_active'       => $this->has('is_active') ? $this->input('is_active') : '1',
        ]);
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string|max:255',
            'barcode'                => 'nullable|string|max:100',
            'is_active'              => 'nullable|in:true,false,1,0',
            'scalar'                 => 'in:pcs',
            // A Compound is a virtual template — grouping it under a "Selling
            // Category" is optional (e.g. "عطور جاهزة" for reporting), never required.
            'category_id'            => 'nullable|exists:categories,id',
            'show_in_catalog'        => 'nullable|in:true,false,1,0',
            'product_type'           => 'in:' . Product::TYPE_COMPOUND,
            // A preferred oil, pre-selected (never locked) in the cashier's
            // Assemble-on-Sale dialog — must be an actual oil (Raw Material,
            // category-priced), same definition SalesService::searchOilProducts() uses.
            'default_oil_id'         => [
                'nullable', 'integer', 'exists:products,id',
                function ($attribute, $value, $fail) {
                    if ($value && ! Product::where('id', $value)
                        ->where('product_type', Product::TYPE_RAW_MATERIAL)
                        ->whereHas('category.productType', fn ($q) => $q->where('pricing_source', 'category'))
                        ->exists()) {
                        $fail('الزيت الافتراضي يجب أن يكون مادة خام من فئة زيوت.');
                    }
                },
            ],
        ];
    }
}
