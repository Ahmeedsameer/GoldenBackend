<?php

namespace App\Modules\Sales\Requests;

use App\Models\Safe;
use App\Modules\Sales\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Determine whether the chosen safe is physical so we can enforce payments.
        $isPhysical = false;
        if ($safeId = $this->input('safe_id')) {
            $safe = Safe::with('safeType')->find($safeId);
            $isPhysical = $safe?->safeType?->kind === 'physical';
        } else {
            // Auto-resolved safe will be physical (the shop's default physical safe)
            $isPhysical = true;
        }

        return [
            'name'               => [ 'nullable','string', 'max:255'],
            'phone'              => [ 'nullable','string', 'max:20'],
            'price_type'         => ['required', 'in:wholesale,retail'],
            'safe_id'            => ['nullable', 'integer', 'exists:safes,id'],

            // Pricing engine selector: 'auto' (per-item when configured, else
            // legacy) or 'global' (force the legacy Global-Total workflow).
            'pricing_mode'       => ['nullable', 'in:auto,global'],

            // Payment breakdown — required for physical safes
            'payments'               => [$isPhysical ? 'required' : 'nullable', 'array', 'min:1'],
            'payments.*.currency_id' => ['required_with:payments', 'integer', 'exists:currencies,id'],
            'payments.*.amount'      => ['required_with:payments', 'numeric', 'min:0.01'],

            // Payment method — one of the supported methods (cash, visa, …)
            'payments.*.payment_method' => ['required_with:payments', Rule::in(PaymentMethod::values())],

            // Transaction/reference number — required only for methods that need
            // it (e.g. Visa). Rejected as a duplicate if the same reference was
            // already used on any previous payment.
            'payments.*.transaction_number' => [
                'nullable',
                'required_if:payments.*.payment_method,' . implode(',', PaymentMethod::requiresTransactionNumber()),
                'string',
                'max:100',
                Rule::unique('invoice_payments', 'transaction_number'),
            ],

            'total_amount'       => ['required', 'numeric', 'min:0.01'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.001'],
            // Manual per-line unit price (cashier can override the configured price).
            'items.*.price'      => ['nullable', 'numeric', 'min:0'],

            // Compose-dialog tagging only (display grouping) — never affects
            // pricing/FIFO. Both null for every normal/legacy line.
            'items.*.parent_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.role'              => ['nullable', 'in:oil,bottle'],

            // Cache-based override token — set when manager has approved the request
            'override_token'     => ['nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
          
            'name.max'                        => 'اسم العميل طويل جداً',
          
            'phone.max'                       => 'رقم الهاتف طويل جداً',
            'price_type.required'             => 'نوع السعر مطلوب',
            'price_type.in'                   => 'نوع السعر يجب أن يكون (جملة) أو (قطاعي)',
            'safe_id.exists'                  => 'الخزنة المحددة غير موجودة في النظام',
            'payments.required'               => 'يرجى إدخال تفاصيل الدفع المستلم للخزنة النقدية',
            'payments.array'                  => 'صيغة بيانات الدفع غير صحيحة',
            'payments.min'                    => 'يجب إضافة طريقة دفع واحدة على الأقل',
            'payments.*.currency_id.required_with' => 'العملة مطلوبة لكل صف دفع',
            'payments.*.currency_id.exists'   => 'العملة المحددة غير موجودة في النظام',
            'payments.*.amount.required_with' => 'المبلغ مطلوب لكل صف دفع',
            'payments.*.amount.min'           => 'يجب أن يكون المبلغ أكبر من صفر',
            'payments.*.payment_method.required_with' => 'طريقة الدفع مطلوبة لكل صف دفع',
            'payments.*.payment_method.in'            => 'طريقة الدفع المحددة غير مدعومة',
            'payments.*.transaction_number.required_if' => 'رقم عملية فيزا مطلوب عند اختيار الدفع بالفيزا',
            'payments.*.transaction_number.max'         => 'رقم العملية طويل جداً',
            'payments.*.transaction_number.unique'      => 'رقم العملية مُستخدم من قبل في فاتورة أخرى',
            'items.required'                  => 'يجب إضافة صنف واحد على الأقل في الفاتورة',
            'items.array'                     => 'صيغة الأصناف غير صحيحة',
            'items.min'                       => 'يجب إضافة صنف واحد على الأقل في الفاتورة',
            'total_amount.required'           => 'إجمالي الفاتورة مطلوب',
            'total_amount.numeric'            => 'إجمالي الفاتورة يجب أن يكون رقماً',
            'total_amount.min'                => 'إجمالي الفاتورة يجب أن يكون أكبر من صفر',
            'items.*.product_id.required'     => 'المنتج مطلوب لكل صنف',
            'items.*.product_id.exists'       => 'المنتج المحدد غير موجود في النظام',
            'items.*.quantity.required'       => 'الكمية مطلوبة لكل صنف',
            'items.*.quantity.numeric'        => 'الكمية يجب أن تكون رقماً',
            'items.*.quantity.min'            => 'الكمية يجب أن تكون أكبر من صفر',
        ];
    }
}
