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
            'date'           => 'sometimes|required|date',
            'payment_method' => 'sometimes|required|in:debt,immediate',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required'    => 'يجب تحديد المورد',
            'supplier_id.exists'      => 'المورد المحدد غير موجود في النظام',
            'date.required'           => 'تاريخ التوريد مطلوب',
            'date.date'               => 'صيغة التاريخ غير صحيحة',
            'payment_method.required' => 'طريقة الدفع مطلوبة',
            'payment_method.in'       => 'طريقة الدفع يجب أن تكون آجل أو فوري',
        ];
    }
}
