<?php

namespace App\Modules\Safe\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSafeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'shop_id'      => ['nullable', 'exists:shops,id'],
            'safe_type_id' => ['required', 'exists:safe_types,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'shop_id.exists'      => 'الفرع المحدد غير موجود',
            'safe_type_id.required' => 'نوع الخزنة مطلوب',
            'safe_type_id.exists'   => 'نوع الخزنة المحدد غير موجود',
        ];
    }
}
