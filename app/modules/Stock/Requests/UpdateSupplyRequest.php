<?php

namespace App\Modules\Stock\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'    => 'sometimes|required|integer|exists:suppliers,id',
            'payment_method' => 'sometimes|required|in:debt,immediate',
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:100', \Illuminate\Validation\Rule::unique('supplies', 'invoice_number')->ignore($this->route('id'))],
            'discount'       => 'sometimes|nullable|numeric|min:0',
            'tax'            => 'sometimes|nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required'    => 'يجب تحديد المورد',
            'supplier_id.exists'      => 'المورد المحدد غير موجود في النظام',
            'payment_method.required' => 'طريقة الدفع مطلوبة',
            'payment_method.in'       => 'طريقة الدفع يجب أن تكون آجل أو فوري',
        ];
    }
}
