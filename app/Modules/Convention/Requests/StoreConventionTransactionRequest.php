<?php

namespace App\Modules\Convention\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class StoreConventionTransactionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'manager_id' => ['required', 'exists:users,id'],
            'amount'     => ['required', 'numeric', 'min:0.01'],
            'reason'     => ['required', 'string', 'max:255', new NoHtmlTags()],
            'notes'      => ['nullable', 'string', 'max:1000', new NoHtmlTags()],
        ];
    }

    public function messages(): array
    {
        return [
            'manager_id.required' => 'المدير مطلوب',
            'manager_id.exists'   => 'المدير المحدد غير موجود',
            'amount.required'     => 'المبلغ مطلوب',
            'amount.numeric'      => 'المبلغ يجب أن يكون رقماً',
            'amount.min'          => 'المبلغ يجب أن يكون أكبر من صفر',
            'reason.required'     => 'سبب الصرف مطلوب',
            'reason.max'          => 'سبب الصرف طويل جداً',
            'notes.max'           => 'الملاحظات طويلة جداً',
        ];
    }
}
