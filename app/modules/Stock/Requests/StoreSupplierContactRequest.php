<?php

namespace App\Modules\Stock\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|max:20',
            'address'  => 'required|string|max:255',
            'position' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'اسم جهة الاتصال مطلوب',
            'phone.required'   => 'رقم الهاتف مطلوب',
            'address.required' => 'العنوان مطلوب',
        ];
    }
}
