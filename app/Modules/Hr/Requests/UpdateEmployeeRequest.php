<?php

namespace App\Modules\Hr\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name'                         => ['sometimes', 'required', 'string', 'max:255', new NoHtmlTags()],
            'email'                        => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password'                     => ['nullable', 'string', 'min:6'],
            'phone'                        => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($id)],
            'role'                         => ['sometimes', 'required', Rule::in(['manager', 'sales'])],
            'status'                       => ['nullable', Rule::in(['active', 'inactive'])],
            'base_salary'                  => ['nullable', 'numeric', 'min:0'],
            'personal_commission_percent'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hire_date'                    => ['nullable', 'date'],
            'monthly_leave_allowance'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'hr_notes'                     => ['nullable', 'string', new NoHtmlTags()],
            'shop_id'                      => ['sometimes', 'required', 'integer', 'exists:shops,id'],
        ];
    }

    public function messages()
    {
        return [
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
        ];
    }
}
