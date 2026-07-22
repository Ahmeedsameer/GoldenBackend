<?php

namespace App\Modules\Stock\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supplierId = $this->route('id');

        return [
            'name'  => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20|unique:suppliers,phone,' . $supplierId,
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'اسم المورد مطلوب',
            'name.max'       => 'اسم المورد يجب ألا يتجاوز 255 حرفاً',
            'phone.required' => 'رقم هاتف المورد مطلوب',
            'phone.max'      => 'رقم الهاتف يجب ألا يتجاوز 20 رقماً',
            'phone.unique'   => 'رقم الهاتف مسجّل لدى مورد آخر بالفعل',
        ];
    }
}
