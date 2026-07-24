<?php

namespace App\Http\Requests;

use App\Models\Product;

/**
 * Ready Product (pre-bottled perfumes sold directly) — real inventory,
 * always shown in the Sales Catalog (the products table's `show_in_catalog`
 * column defaults to true, so simply omitting it here keeps that default).
 * Per the ERP spec, this creation form carries identity + inventory-unit
 * fields ONLY; pricing (selling_price/purchase_cost) is set exclusively in
 * Pricing Management, and there's no image/catalog-visibility toggle here.
 */
class StoreReadyProductRequest extends CreateProductRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_type' => Product::TYPE_READY_PRODUCT,
            'is_active'    => $this->has('is_active') ? $this->input('is_active') : '1',
        ]);
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'barcode'           => 'nullable|string|max:100',
            'is_active'         => 'nullable|in:true,false,1,0',
            // Piece, or weighed by gram/kilogram — kilogram is normalized to
            // gram client-side before submission (see ready-product-create
            // component), so only the base units ever reach here.
            'scalar'            => 'required|in:pcs,g',
            'category_id'       => 'required|exists:categories,id',
            'warning_quantity'  => 'nullable|numeric|min:0',
            'critical_quantity' => 'nullable|numeric|min:0|lte:warning_quantity',
            'product_type'      => 'in:' . Product::TYPE_READY_PRODUCT,
        ];
    }
}
