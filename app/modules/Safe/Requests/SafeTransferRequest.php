<?php

namespace App\Modules\Safe\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SafeTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'from_safe_id' => ['required', 'exists:safes,id'],
            'to_safe_id'   => ['required', 'exists:safes,id', 'different:from_safe_id'],
            'currency_id'  => ['required', 'exists:currencies,id'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'note'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_safe_id.required'   => 'خزنة المصدر مطلوبة',
            'from_safe_id.exists'     => 'خزنة المصدر غير موجودة',
            'to_safe_id.required'     => 'خزنة الوجهة مطلوبة',
            'to_safe_id.exists'       => 'خزنة الوجهة غير موجودة',
            'to_safe_id.different'    => 'لا يمكن التحويل من خزنة إلى نفسها',
            'currency_id.required'    => 'العملة مطلوبة',
            'currency_id.exists'      => 'العملة المحددة غير موجودة',
            'amount.required'         => 'المبلغ مطلوب',
            'amount.numeric'          => 'المبلغ يجب أن يكون رقماً',
            'amount.min'              => 'المبلغ يجب أن يكون أكبر من صفر',
        ];
    }
}
