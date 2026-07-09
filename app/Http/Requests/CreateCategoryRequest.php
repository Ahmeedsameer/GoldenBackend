<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoryRequest extends FormRequest
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
        // The Product Type is the source of truth for which pricing fields are
        // required. Oil-type categories (pricing_source = 'category') price per
        // gram at the category level → minimum_sell_price + price_per_gram are
        // required. Every other type prices at the product level → those fields
        // are ignored. New types are handled automatically via pricing_source.
        $pricesAtCategory = false;
        if ($typeId = $this->input('product_type_id')) {
            $type = \App\Models\ProductType::find($typeId);
            $pricesAtCategory = $type && $type->pricing_source === 'category';
        }

        return [
            'name'                => 'required|string|unique:categories,name',
            'description'         => 'nullable|string',
            'parent_id'           => 'nullable|exists:categories,id',
            'image'               => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'product_type_id'     => 'required|exists:product_types,id',

            // Category-level pricing — required only for category-priced types (oil).
            // Note: per-gram price now lives on the product, so it's no longer
            // requested here. The category keeps the floor (minimum_sell_price).
            'minimum_sell_price'  => [$pricesAtCategory ? 'required' : 'nullable', 'numeric', 'min:0'],
            'price_per_gram'      => 'nullable|numeric|min:0',

            // is_fixed is derived from the Product Type in the service (not the client).
            'is_fixed'            => 'nullable|boolean',
            'value_percentage'    => 'nullable|numeric|min:0|max:100',
            'default_warning_quantity'  => 'nullable|numeric|min:0',
            'default_critical_quantity' => 'nullable|numeric|min:0',
        ];
    }


        public function messages() {
            return [
                'name.required' => 'يجب إدخال اسم الفئة',
                'name.string' => 'يجب أن يكون اسم الفئة نصًا',
                'name.unique' => 'اسم الفئة موجود بالفعل',
                'description.string' => 'يجب أن يكون الوصف نصًا',
                'parent_id.exists' => 'الفئة الأب غير موجودة',
                'image.image' => 'يجب أن يكون الملف صورة',
                'image.mimes' => 'يجب أن يكون الملف من نوع jpeg, png, jpg, gif, svg',
                'image.max'                   => 'يجب أن لا يتجاوز حجم الصورة 2048 كيلوبايت',
                'minimum_sell_price.required' => 'الحد الأدنى لسعر البيع مطلوب',
                'minimum_sell_price.numeric'  => 'الحد الأدنى لسعر البيع يجب أن يكون رقماً',
                'minimum_sell_price.min'      => 'الحد الأدنى لسعر البيع لا يمكن أن يكون سالباً',
                'is_fixed.required'           => 'نوع التسعير مطلوب',
                'is_fixed.boolean'            => 'نوع التسعير يجب أن يكون صحيح أو خطأ',
                'value_percentage.numeric'    => 'نسبة القيمة يجب أن تكون رقماً',
                'value_percentage.min'        => 'نسبة القيمة لا يمكن أن تكون سالبة',
                'value_percentage.max'        => 'نسبة القيمة لا يمكن أن تتجاوز 100',
            ];
        }
}
