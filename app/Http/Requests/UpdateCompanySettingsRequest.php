<?php

namespace App\Http\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', new NoHtmlTags()],
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:50',
            'address'     => ['nullable', 'string', 'max:500', new NoHtmlTags()],
            'website'     => 'nullable|string|max:255',
            'description' => ['nullable', 'string', 'max:2000', new NoHtmlTags()],
            'logo'        => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم الشركة مطلوب',
            'email.email'   => 'صيغة البريد الإلكتروني غير صحيحة',
            'logo.image'    => 'يجب أن يكون الشعار صورة',
            'logo.mimes'    => 'صيغ الشعار المسموحة: png, jpg, jpeg, svg, webp',
            'logo.max'      => 'حجم الشعار يجب ألا يتجاوز 2 ميجابايت',
        ];
    }
}
