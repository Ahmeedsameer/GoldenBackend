<?php

namespace App\Modules\Safe\Requests;

use App\Rules\NoHtmlTags;
use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code'   => ['required', 'string', 'max:10', 'unique:currencies,code'],
            'name'   => ['required', 'string', 'max:100', new NoHtmlTags()],
            'symbol' => ['required', 'string', 'max:10'],
            'rate'   => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'   => 'رمز العملة مطلوب',
            'code.unique'     => 'رمز العملة موجود بالفعل',
            'code.max'        => 'رمز العملة لا يتجاوز 10 أحرف',
            'name.required'   => 'اسم العملة مطلوب',
            'symbol.required' => 'رمز العملة القصير مطلوب',
            'rate.required'   => 'سعر الصرف مطلوب',
            'rate.numeric'    => 'سعر الصرف يجب أن يكون رقماً',
            'rate.min'        => 'سعر الصرف يجب أن يكون أكبر من صفر',
        ];
    }
}
