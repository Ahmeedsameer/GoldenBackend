<?php

namespace App\Http\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class ShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopId = $this->route('shop');

        return [
            'name' => ['required', 'string', 'max:255', new NoHtmlTags()],
            'address' => ['required', 'string', new NoHtmlTags()],
            'username' => 'required|string|min:3|unique:shops,username,' . $shopId,
            'password' => 'required|string|min:6',
            'status' => 'sometimes|in:active,inactive'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'حقل الاسم مطلوب',
            'name.max' => 'الاسم يجب ان لا يتجاوز 255 حرف',
            'address.required' => 'حقل العنوان مطلوب',
            'username.required' => 'حقل اسم المستخدم مطلوب',
            'username.min' => 'اسم المستخدم يجب ان يكون 3 احرف على الاقل',
            'username.unique' => 'اسم المستخدم مستخدم مسبقا',
            'password.required' => 'حقل كلمة المرور مطلوب',
            'password.min' => 'كلمة المرور يجب ان تكون 6 احرف على الاقل',
            'status.in' => 'الحالة يجب ان تكون active او inactive'
        ];
    }
}