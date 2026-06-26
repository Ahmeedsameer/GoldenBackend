<?php

namespace App\Modules\Safe\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            'direction' => ['required', 'in:in,out,both'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم السبب مطلوب',
            'direction.required' => 'اتجاه المعاملة مطلوب',
            'direction.in'       => 'الاتجاه يجب أن يكون (وارد) أو (صادر) أو (كلاهما)',
        ];
    }
}
