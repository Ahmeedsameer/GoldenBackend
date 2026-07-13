<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopId = $this->route('id');

        return [
            'name'                 => 'sometimes|required|string|max:255',
            'branch_bonus_percent' => 'nullable|numeric|min:0|max:100',
            'address'              => 'sometimes|required|string',
            'username'   => 'sometimes|required|string|min:3|max:255|unique:shops,username,' . $shopId,
            'password'   => 'nullable|string|min:6',
            'status'     => 'sometimes|in:active,inactive',
            'manager_id' => 'nullable|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'حقل اسم الفرع مطلوب',
            'name.max'           => 'اسم الفرع يجب ألا يتجاوز 255 حرفاً',
            'address.required'   => 'حقل عنوان الفرع مطلوب',
            'username.required'  => 'حقل اسم المستخدم مطلوب',
            'username.min'       => 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل',
            'username.unique'    => 'اسم المستخدم مستخدم مسبقاً',
            'password.min'       => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل',
            'status.in'          => 'الحالة يجب أن تكون active أو inactive',
            'manager_id.exists'  => 'المدير المحدد غير موجود في النظام',
        ];
    }
}
