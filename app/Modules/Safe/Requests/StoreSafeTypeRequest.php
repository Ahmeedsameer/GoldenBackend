<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class StoreSafeTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new NoHtmlTags()],
            'kind' => ['required', 'in:physical,virtual'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم النوع مطلوب',
            'kind.required' => 'نوع الخزنة مطلوب',
            'kind.in'       => 'نوع الخزنة يجب أن يكون (نقدية) أو (افتراضية)',
        ];
    }
}
