<?php

namespace App\Http\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOwnProfileRequest extends FormRequest
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
        // Always the AUTHENTICATED admin's own record — never a client-supplied
        // ID, so this endpoint can never be used to edit someone else's account.
        $userId = auth()->id();

        return [
            'name'  => ['required', 'string', 'max:255', new NoHtmlTags()],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', Rule::unique('users', 'phone')->ignore($userId)],
        ];
    }

    public function messages()
    {
        return [
            'name.required'  => 'الاسم مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.email'    => 'البريد الإلكتروني غير صالح',
            'email.unique'   => 'البريد الإلكتروني مستخدم بالفعل',
            'phone.unique'   => 'رقم الهاتف مستخدم بالفعل',
        ];
    }
}
