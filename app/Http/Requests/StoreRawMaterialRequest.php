<?php

namespace App\Http\Requests;

use App\Models\Product;

/**
 * Raw Material (oils, alcohol, oud, carrier bases) — inventory-only, never
 * shown in the Sales Catalog. Sold/tracked by weight (g) or volume (ml) —
 * never by the piece. Per the ERP spec, this creation form carries identity +
 * inventory-unit fields ONLY; pricing (selling_price/price_per_gram/purchase_cost)
 * is set exclusively in Pricing Management, never here.
 */
class StoreRawMaterialRequest extends CreateProductRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_type'    => Product::TYPE_RAW_MATERIAL,
            // Merged as a string, not a PHP bool — Laravel's `in:` rule casts
            // the value to string for comparison, and `(string) false === ''`,
            // which would fail an `in:false,0` check.
            'show_in_catalog' => '0',
            // Not a field on this form — defaults active unless the caller
            // explicitly overrides it (e.g. a future re-activation flow).
            'is_active'       => $this->has('is_active') ? $this->input('is_active') : '1',
        ]);
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'barcode'           => 'nullable|string|max:100',
            'is_active'         => 'nullable|in:true,false,1,0',
            // Raw Materials are never counted by the piece — weight or volume
            // only. The form only ever offers g/ml (kg/l are purchase-time
            // conveniences normalized down before submission), so this stays
            // strict to catch anything else.
            'scalar'            => 'required|in:g,ml',
            'category_id'       => 'required|exists:categories,id',
            'warning_quantity'  => 'nullable|numeric|min:0',
            'critical_quantity' => 'nullable|numeric|min:0|lte:warning_quantity',
            'product_type'      => 'in:' . Product::TYPE_RAW_MATERIAL,
            'show_in_catalog'   => 'in:false,0',
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'scalar.required' => 'وحدة القياس مطلوبة',
            'scalar.in'       => 'المواد الخام تُقاس بالوزن (جرام) أو الحجم (مليلتر) فقط',
        ]);
    }
}
