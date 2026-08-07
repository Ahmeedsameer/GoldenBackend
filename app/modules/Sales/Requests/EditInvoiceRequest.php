<?php

namespace App\Modules\Sales\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit Invoice (Admin / Sales Manager only) — deliberately a smaller shape
 * than StoreInvoiceRequest: an edit never re-collects a payments breakdown
 * (the financial difference is settled by a single safe adjustment
 * transaction instead, see SalesService::editInvoice()), and never goes
 * through the pending-approval/override-token flow a seller's sale does.
 */
class EditInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.001'],
            'items.*.price'      => [
                Rule::requiredIf(fn () => $this->input('pricing_mode') === 'global'),
                'nullable', 'numeric', 'min:0',
            ],
            'items.*.parent_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.role'              => ['nullable', 'in:oil,bottle,alcohol'],
            'pricing_mode'       => ['nullable', 'in:auto,global'],

            // Only required when the recalculated total actually differs
            // from the original — SalesService::editInvoice() itself aborts
            // if a real difference has no safe_id, this is just the shape.
            'safe_id'            => ['nullable', 'integer', 'exists:safes,id'],
            'note'               => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'                  => 'يجب إضافة صنف واحد على الأقل في الفاتورة',
            'items.min'                       => 'يجب إضافة صنف واحد على الأقل في الفاتورة',
            'items.*.product_id.required'     => 'المنتج مطلوب لكل صنف',
            'items.*.product_id.exists'       => 'المنتج المحدد غير موجود في النظام',
            'items.*.quantity.required'       => 'الكمية مطلوبة لكل صنف',
            'items.*.quantity.min'            => 'الكمية يجب أن تكون أكبر من صفر',
            'items.*.price.required'          => 'يجب إدخال سعر يدوي لكل صنف في وضع الإجمالي اليدوي.',
            'safe_id.exists'                  => 'الخزنة المحددة غير موجودة في النظام',
        ];
    }
}
