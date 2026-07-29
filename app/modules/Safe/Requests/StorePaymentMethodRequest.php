<?php

namespace App\Modules\Safe\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Bank Cards module — the issuing bank (CIB, QNB, Banque Misr, HSBC, ...),
            // only meaningful for card types, same "server never trusts the frontend
            // hiding it" pattern as processing_fee_percent.
            'bank' => ['nullable', 'string', 'max:100'],
            // Mobile wallet methods (Vodafone Cash, InstaPay, ...) capture the wallet's phone number at creation.
            'wallet_phone' => ['nullable', 'string', 'max:30'],
            'type' => ['required', 'string', 'in:' . implode(',', PaymentMethod::TYPES)],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            // Only meaningful for card types — SalesController/frontend hide the field
            // otherwise, but the server never trusts that and zeroes it out itself
            // (see PaymentMethodController::normalizeFee()) rather than rejecting it here.
            'processing_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            // Assigned safe — every payment via this method auto-credits it, no manual pick at sale time. Null = fall back to the branch's default physical safe.
            'safe_id' => ['nullable', 'integer', 'exists:safes,id'],
            // Optional branch restriction — omit/empty = unrestricted (every active branch can use it).
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'exists:shops,id'],
        ];
    }
}
