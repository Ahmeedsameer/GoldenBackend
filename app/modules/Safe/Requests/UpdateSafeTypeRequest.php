<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSafeTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:100', new NoHtmlTags()],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'اسم النوع مطلوب',
            'is_active.boolean' => 'الحالة يجب أن تكون صحيح أو خطأ',
        ];
    }
}
