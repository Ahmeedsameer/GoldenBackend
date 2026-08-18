<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class SafeTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'from_safe_id' => ['required', 'exists:safes,id'],
            // Sub Safes: to_safe_id may equal from_safe_id ONLY for a same-branch
            // child-safe (payment method) transfer — enforced in withValidator()
            // below, since "different" alone can't express that conditional.
            'to_safe_id'   => ['required', 'exists:safes,id'],
            'currency_id'  => ['required', 'exists:currencies,id'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'note'         => ['nullable', 'string', 'max:500', new NoHtmlTags()],
            'from_payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'to_payment_method_id'   => ['nullable', 'exists:payment_methods,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from   = $this->input('from_safe_id');
            $to     = $this->input('to_safe_id');
            $fromPm = $this->input('from_payment_method_id');
            $toPm   = $this->input('to_payment_method_id');

            if ($from && $to && (string) $from === (string) $to) {
                if (! $fromPm || ! $toPm) {
                    $validator->errors()->add('to_safe_id', 'لا يمكن التحويل من خزنة إلى نفسها إلا بين وسيلتي دفع مختلفتين داخل نفس الخزنة');
                } elseif ((string) $fromPm === (string) $toPm) {
                    $validator->errors()->add('to_payment_method_id', 'يجب اختيار وسيلة دفع مختلفة عن وسيلة المصدر');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'from_safe_id.required'   => 'خزنة المصدر مطلوبة',
            'from_safe_id.exists'     => 'خزنة المصدر غير موجودة',
            'to_safe_id.required'     => 'خزنة الوجهة مطلوبة',
            'to_safe_id.exists'       => 'خزنة الوجهة غير موجودة',
            'currency_id.required'    => 'العملة مطلوبة',
            'currency_id.exists'      => 'العملة المحددة غير موجودة',
            'amount.required'         => 'المبلغ مطلوب',
            'amount.numeric'          => 'المبلغ يجب أن يكون رقماً',
            'amount.min'              => 'المبلغ يجب أن يكون أكبر من صفر',
        ];
    }
}
