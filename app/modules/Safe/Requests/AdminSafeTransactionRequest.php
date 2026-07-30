<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class AdminSafeTransactionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'currency_id'       => ['required', 'exists:currencies,id'],
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'reason_id'         => ['required', 'exists:transaction_reasons,id'],
            'note'              => ['nullable', 'string', 'max:500', new NoHtmlTags()],
            // Sub Safes: null = "Entire Safe (Automatic)", exactly today's behavior.
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_id.required' => 'العملة مطلوبة',
            'currency_id.exists'   => 'العملة المحددة غير موجودة',
            'amount.required'      => 'المبلغ مطلوب',
            'amount.numeric'       => 'المبلغ يجب أن يكون رقماً',
            'amount.min'           => 'المبلغ يجب أن يكون أكبر من صفر',
            'reason_id.required'   => 'سبب المعاملة مطلوب',
            'reason_id.exists'     => 'السبب المحدد غير موجود',
            'note.max'             => 'الملاحظة طويلة جداً',
        ];
    }
}
