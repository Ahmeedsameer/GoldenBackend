<?php

namespace App\Http\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class updateCategoryRequest extends FormRequest
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
            'name'               => ['sometimes', 'required', 'string', 'unique:categories,name,' . $this->route('id'), new NoHtmlTags()],
            'description'        => ['nullable', 'string', new NoHtmlTags()],
            'parent_id'          => 'nullable|exists:categories,id',
            'image'              => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'minimum_sell_price' => 'sometimes|required|numeric|min:0',
            'default_selling_price' => ['nullable', 'numeric', 'min:0', 'gte:minimum_sell_price'],
            'is_fixed'           => 'sometimes|required|boolean',
            'value_percentage'   => 'nullable|numeric|min:0|max:100',
            'price_per_gram'     => 'nullable|numeric|min:0',
            'product_type_id'    => 'nullable|exists:product_types,id',
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
            'default_selling_price.numeric' => 'السعر الافتراضي يجب أن يكون رقماً',
            'default_selling_price.min'      => 'السعر الافتراضي لا يمكن أن يكون سالباً',
            'default_selling_price.gte'      => 'السعر الافتراضي لا يمكن أن يكون أقل من الحد الأدنى لسعر البيع',
            'is_fixed.boolean'            => 'نوع التسعير يجب أن يكون صحيح أو خطأ',
            'value_percentage.numeric'    => 'نسبة القيمة يجب أن تكون رقماً',
            'value_percentage.min'        => 'نسبة القيمة لا يمكن أن تكون سالبة',
            'value_percentage.max'        => 'نسبة القيمة لا يمكن أن تتجاوز 100',
        ];
    }
}
