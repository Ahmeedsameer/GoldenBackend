<?php

namespace App\Modules\Stock\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goods_id'   => 'required|integer|exists:goods,id',
            'quantity'   => 'required|numeric|min:0.001',
            'to_shop_id' => 'nullable|integer|exists:shops,id',
        ];
    }

    public function messages(): array
    {
        return [
            'goods_id.required'  => 'يجب تحديد سجل البضاعة المراد نقلها',
            'goods_id.exists'    => 'سجل البضاعة المحدد غير موجود في النظام',
            'quantity.required'  => 'كمية النقل مطلوبة',
            'quantity.numeric'   => 'كمية النقل يجب أن تكون رقماً',
            'quantity.min'       => 'كمية النقل يجب أن تكون أكبر من صفر',
            'to_shop_id.integer' => 'معرّف الفرع المستهدف غير صحيح',
            'to_shop_id.exists'  => 'الفرع المستهدف غير موجود في النظام',
        ];
    }
}
