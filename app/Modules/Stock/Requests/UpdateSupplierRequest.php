<?php

namespace App\Modules\Stock\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supplierId = $this->route('id');

        return [
            'name'                 => ['sometimes', 'required', 'string', 'max:255', new NoHtmlTags()],
            'phone'                => 'sometimes|required|string|max:20|unique:suppliers,phone,' . $supplierId,
            'address'              => ['sometimes', 'required', 'string', 'max:255', new NoHtmlTags()],
            'bank_account_number'  => ['nullable', 'string', 'max:100', new NoHtmlTags()],
            'mobile_wallet'        => ['nullable', 'string', 'max:100', new NoHtmlTags()],
            'instapay'             => ['nullable', 'string', 'max:100', new NoHtmlTags()],
            'iban'                 => ['nullable', 'string', 'max:100', new NoHtmlTags()],
            'opening_balance'      => 'nullable|numeric|min:0',
            'notes'                => ['nullable', 'string', new NoHtmlTags()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'اسم المورد مطلوب',
            'name.max'       => 'اسم المورد يجب ألا يتجاوز 255 حرفاً',
            'phone.required' => 'رقم هاتف المورد مطلوب',
            'phone.max'      => 'رقم الهاتف يجب ألا يتجاوز 20 رقماً',
            'phone.unique'   => 'رقم الهاتف مسجّل لدى مورد آخر بالفعل',
        ];
    }
}
