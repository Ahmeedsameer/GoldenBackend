<?php

namespace App\Modules\Safe\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:150'],
            'direction' => ['sometimes', 'required', 'in:in,out,both'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم السبب مطلوب',
            'direction.in'       => 'الاتجاه يجب أن يكون (وارد) أو (صادر) أو (كلاهما)',
            'is_active.boolean'  => 'الحالة يجب أن تكون صحيح أو خطأ',
        ];
    }
}
