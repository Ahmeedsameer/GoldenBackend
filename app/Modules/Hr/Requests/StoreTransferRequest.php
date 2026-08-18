<?php

namespace App\Modules\Hr\Requests;

use App\Models\User;
use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // behind CheckRole:admin
    }

    public function rules(): array
    {
        return [
            'user_id'             => ['required', 'integer', Rule::exists('users', 'id')->whereIn('role', ['manager', 'sales'])],
            'temporary_branch_id' => ['required', 'integer', 'exists:shops,id'],
            'start_date'          => ['required', 'date'],
            'end_date'            => ['required', 'date', 'after_or_equal:start_date'],
            'reason'              => ['nullable', 'string', new NoHtmlTags()],
            'notes'               => ['nullable', 'string', new NoHtmlTags()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $employee = User::find($this->input('user_id'));
            if ($employee && (int) $employee->shop_id === (int) $this->input('temporary_branch_id')) {
                $v->errors()->add('temporary_branch_id', 'الفرع المؤقت لا يمكن أن يكون نفس الفرع الأساسي للموظف.');
            }
        });
    }
}
