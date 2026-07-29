<?php

namespace App\Modules\Safe\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'bank' => ['nullable', 'string', 'max:100'],
            'wallet_phone' => ['nullable', 'string', 'max:30'],
            'type' => ['sometimes', 'string', 'in:' . implode(',', PaymentMethod::TYPES)],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'processing_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'safe_id' => ['nullable', 'integer', 'exists:safes,id'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'exists:shops,id'],
        ];
    }
}
