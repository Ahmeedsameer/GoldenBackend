<?php

namespace App\Modules\Shop\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:shops,username',
            'password' => 'nullable|string|min:8',
            'status' => 'required|in:active,inactive',
            'manager_id' => 'nullable|exists:users,id'
          
        ];
    }


    public function messages() {
     
        return [
            'name.required' => 'اسم المتجر مطلوب',
            'name.string' => 'اسم المتجر يجب أن يكون نصًا',
            'name.max' => 'اسم المتجر لا يجب أن يتجاوز 255 حرفًا',
            'address.required' => 'عنوان المتجر مطلوب',
            'address.string' => 'عنوان المتجر يجب أن يكون نصًا',
            'address.max' => 'عنوان المتجر لا يجب أن يتجاوز 255 حرفًا',
            'username.string' => 'اسم المستخدم يجب أن يكون نصًا',
            'username.max' => 'اسم المستخدم لا يجب أن يتجاوز 255 حرفًا',
            'username.unique' => 'اسم المستخدم مستخدم بالفعل',
            'password.string' => 'كلمة المرور يجب أن تكون نصًا',
            'password.min' => 'كلمة المرور يجب أن تكون على الأقل 8 أحرف',
            'status.required' => 'حالة المتجر مطلوبة',
            'status.in' => 'حالة المتجر يجب أن تكون إما active أو inactive',
            'manager_id.exists' => 'المدير المحدد غير موجود'
        ];
    }
}
