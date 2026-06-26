<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'sku' => 'required|string|max:100|unique:products,sku',
            'description' => 'nullable|string',
            'is_active' => 'required|in:true,false,1,0',
            'scalar' => 'required|in:kg,g,l,ml,pcs',
            'category_id' => 'required|exists:categories,id',
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
