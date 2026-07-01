<?php

namespace App\Modules\Convention\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConventionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'shop_id'  => ['required', 'exists:shops,id'],
            'admin_id' => ['nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'  => 'مبلغ العهدة مطلوب',
            'amount.numeric'   => 'مبلغ العهدة يجب أن يكون رقماً',
            'amount.min'       => 'مبلغ العهدة يجب أن يكون أكبر من صفر',
            'shop_id.required' => 'الفرع مطلوب',
            'shop_id.exists'   => 'الفرع المحدد غير موجود',
            'admin_id.exists'  => 'الأدمن المحدد غير موجود',
        ];
    }
}
