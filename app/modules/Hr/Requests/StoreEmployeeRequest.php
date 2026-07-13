<?php

namespace App\Modules\Hr\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind CheckRole:admin
    }

    public function rules(): array
    {
        return [
            'name'                         => ['required', 'string', 'max:255'],
            'email'                        => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'                     => ['required', 'string', 'min:6'],
            'phone'                        => ['nullable', 'string', 'max:30'],
            // Employees may only be Branch Manager or Sales (never admin).
            'role'                         => ['required', Rule::in(['manager', 'sales'])],
            'status'                       => ['nullable', Rule::in(['active', 'inactive'])],
            'base_salary'                  => ['nullable', 'numeric', 'min:0'],
            'personal_commission_percent'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hire_date'                    => ['nullable', 'date'],
            'monthly_leave_allowance'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'hr_notes'                     => ['nullable', 'string'],

            // Primary (permanent) branch.
            'shop_id'                      => ['required', 'integer', 'exists:shops,id'],
        ];
    }
}
