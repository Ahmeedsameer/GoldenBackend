<?php

namespace App\Modules\Stock\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Every payment belongs to exactly ONE invoice — never a bare
            // supplier-level amount (see SupplierPaymentService::pay()).
            'supply_id'   => 'required|integer|exists:supplies,id',
            'safe_id'     => 'required|integer|exists:safes,id',
            'currency_id' => 'required|integer|exists:currencies,id',
            'amount'      => 'required|numeric|min:0.01',
            'note'        => 'nullable|string|max:255',
            // Optional — reuses the Payment Methods module (Phase 2). When the chosen
            // method has an assigned safe, it overrides `safe_id` automatically.
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
        ];
    }

    public function messages(): array
    {
        return [
            'supply_id.required'   => 'يجب تحديد فاتورة التوريد',
            'supply_id.exists'     => 'فاتورة التوريد المحددة غير موجودة',
            'safe_id.required'     => 'يجب تحديد الخزنة',
            'currency_id.required' => 'يجب تحديد العملة',
            'amount.required'      => 'مبلغ الدفعة مطلوب',
            'amount.min'           => 'مبلغ الدفعة يجب أن يكون أكبر من صفر',
        ];
    }
}
