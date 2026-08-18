<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:100', new NoHtmlTags()],
            'symbol'    => ['sometimes', 'required', 'string', 'max:10'],
            'rate'      => ['sometimes', 'required', 'numeric', 'min:0.0001'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم العملة مطلوب',
            'symbol.required'    => 'رمز العملة القصير مطلوب',
            'rate.required'      => 'سعر الصرف مطلوب',
            'rate.numeric'       => 'سعر الصرف يجب أن يكون رقماً',
            'rate.min'           => 'سعر الصرف يجب أن يكون أكبر من صفر',
            'is_active.boolean'  => 'حالة العملة يجب أن تكون صحيح أو خطأ',
        ];
    }
}
