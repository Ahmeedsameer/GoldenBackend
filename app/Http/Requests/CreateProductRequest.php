<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new NoHtmlTags()],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100',
            'description' => ['nullable', 'string', new NoHtmlTags()],
            'notes'       => ['nullable', 'string', new NoHtmlTags()],
            'is_active' => 'required|in:true,false,1,0',
            'scalar' => 'required|in:kg,g,l,ml,pcs',
            'category_id' => 'required|exists:categories,id',
            // Perfume management: per-product price + stock thresholds (all optional)
            'selling_price'     => 'nullable|numeric|min:0',
            'price_per_gram'    => 'nullable|numeric|min:0',
            'purchase_cost'     => 'nullable|numeric|min:0',
            'warning_quantity'  => 'nullable|numeric|min:0',
            'critical_quantity' => 'nullable|numeric|min:0|lte:warning_quantity',
            // Catalog/BOM tagging — both optional, fully backward compatible.
            'product_type'      => ['nullable', Rule::in(Product::PRODUCT_TYPES)],
            'show_in_catalog'   => 'nullable|in:true,false,1,0',
            // Bottle capacity in ml — only meaningful for PACKAGING products.
            'capacity_ml'       => 'nullable|numeric|min:0',
            // Composite Products only — a preferred oil, pre-selected (never locked)
            // in the cashier's Assemble-on-Sale dialog. NOT a recipe/BOM. Must be an
            // actual oil (same definition SalesService::searchOilProducts() uses).
            'default_oil_id'    => [
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


  public function messages():array{
      
    return [
        'name.required' => 'الاسم مطلوب',
        'name.string' => 'الاسم يجب أن يكون نصًا',
        'name.max' => 'الاسم لا يجب أن يتجاوز 255 حرفًا',
        'image.image' => 'الصورة يجب أن تكون ملف صورة',
        'image.mimes' => 'الصورة يجب أن تكون من نوع jpeg, png, jpg, gif, svg',
        'image.max' => 'الصورة لا يجب أن تتجاوز 2048 كيلوبايت',
        'sku.required' => 'الرمز التعريفي مطلوب',
        'sku.string' => 'الرمز التعريفي يجب أن يكون نصًا',
        'sku.max' => 'الرمز التعريفي لا يجب أن يتجاوز 100 حرفًا',
        'sku.unique' => 'الرمز التعريفي موجود بالفعل',
        'description.string' => 'الوصف يجب أن يكون نصًا',
        'is_active.boolean' => 'الحالة النشطة يجب أن تكون قيمة منطقية',
        'scalar.required' => 'الوحدة المطلوبة',
        'scalar.in' => 'الوحدة يجب أن تكون واحدة من: kg, g, l, ml, pcs',
        'category_id.required' => 'معرف الفئة مطلوب',
        'category_id.exists' => 'معرف الفئة غير موجود في قاعدة البيانات'
    ];

  }

}
