<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Rules\NoHtmlTags;

/**
 * Packaging (bottles, boxes, wrapping) — inventory-only, never shown in the
 * Sales Catalog. Always counted by the piece; bottles additionally carry a
 * physical capacity_ml (optional here — it can also be recorded later on
 * first Supply, see SupplyService::create()). Per the ERP spec, this
 * creation form carries identity + inventory-unit fields ONLY; pricing
 * (selling_price/purchase_cost) is set exclusively in Pricing Management.
 */
class StorePackagingRequest extends CreateProductRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_type'    => Product::TYPE_PACKAGING,
            // Merged as a string, not a PHP bool — Laravel's `in:` rule casts
            // the value to string for comparison, and `(string) false === ''`,
            // which would fail an `in:false,0` check.
            'show_in_catalog' => '0',
            // Packaging is always counted by the piece — never a user choice.
            'scalar'          => 'pcs',
            'is_active'       => $this->has('is_active') ? $this->input('is_active') : '1',
        ]);
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255', new NoHtmlTags()],
            'image'             => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'barcode'           => 'nullable|string|max:100',
            'is_active'         => 'nullable|in:true,false,1,0',
            'scalar'            => 'in:pcs',
            'category_id'       => 'required|exists:categories,id',
            'warning_quantity'  => 'nullable|numeric|min:0',
            'critical_quantity' => 'nullable|numeric|min:0|lte:warning_quantity',
            // Bottles only — boxes/labels/wrapping can leave this empty.
            'capacity_ml'       => 'nullable|numeric|min:0.001',
            'product_type'      => 'in:' . Product::TYPE_PACKAGING,
            'show_in_catalog'   => 'in:false,0',
        ];
    }
}
