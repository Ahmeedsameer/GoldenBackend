<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب تحديد الموظف المراد إضافته',
            'user_id.integer'  => 'معرّف الموظف غير صحيح',
            'user_id.exists'   => 'الموظف المحدد غير موجود في النظام',
        ];
    }
}
