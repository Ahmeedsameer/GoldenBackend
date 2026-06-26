<?php

namespace App\Modules\Sales\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,cancelled'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'حالة الفاتورة مطلوبة',
            'status.in'       => 'الحالة يجب أن تكون (معتمدة) أو (ملغاة)',
        ];
    }
}
