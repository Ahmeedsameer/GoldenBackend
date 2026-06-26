<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|max:16',
            'role' => 'required|string|in:admin,manager,sales',
            'phone' => 'nullable|string|unique:users,phone',
            'shift_start' => 'nullable|date_format:H:i',
            'shift_end' => 'nullable|date_format:H:i|after:shift_start',
        ];
    }

    public function messages() {
        # messages in arabic
        return [
            'name.required' => 'الاسم مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.string' => 'كلمة المرور يجب أن تكون نص',
            'password.min' => 'كلمة المرور يجب أن تكون على الأقل 8 أحرف',
            'password.max' => 'كلمة المرور يجب أن لا تزيد عن 16 حرف',
            'role.required' => 'الدور مطلوب',
            'role.string' => 'الدور يجب أن يكون نص',
            'role.in' => 'الدور يجب أن يكون واحد من: مدير موقع, مدير فرع, بائع',
            'phone.string' => 'رقم الهاتف يجب أن يكون نص',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'shift_start.date_format' => 'وقت بداية الوردية يجب أن يكون بصيغة H:i (مثال: 08:00)',
            'shift_end.date_format' => 'وقت نهاية الوردية يجب أن يكون بصيغة H:i (مثال: 17:00)',
            'shift_end.after' => 'وقت نهاية الوردية يجب أن يكون بعد وقت بداية الوردية',
        ];
    }
}
