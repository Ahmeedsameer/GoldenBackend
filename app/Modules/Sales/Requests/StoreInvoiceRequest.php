<?php

namespace App\Modules\Sales\Requests;

use App\Models\PaymentMethod;
use App\Models\Safe;
use App\Rules\NoHtmlTags;
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
            'name'               => [ 'nullable','string', 'max:255', new NoHtmlTags()],
            'phone'              => [ 'nullable','string', 'max:20', new NoHtmlTags()],
            'price_type'         => ['required', 'in:wholesale,retail'],
            'safe_id'            => ['nullable', 'integer', 'exists:safes,id'],

            // Pricing engine selector: 'auto' (per-item when configured, else
            // legacy) or 'global' (force the legacy Global-Total workflow).
            'pricing_mode'       => ['nullable', 'in:auto,global'],

            // Payment breakdown — required for physical safes
            'payments'               => [$isPhysical ? 'required' : 'nullable', 'array', 'min:1'],
            'payments.*.currency_id' => ['required_with:payments', 'integer', 'exists:currencies,id'],
            'payments.*.amount'      => ['required_with:payments', 'numeric', 'min:0.01'],

            // Payment method — any admin-managed PaymentMethod (unlimited: cash,
            // visa, mastercard, Vodafone Cash, InstaPay, ...), never the old
            // hardcoded 2-value enum. `payment_method` (legacy string) is no
            // longer client-supplied — SalesService derives it from the resolved
            // method's type, kept only for backward-compatible reads of old rows.
            'payments.*.payment_method_id' => ['required_with:payments', 'integer', 'exists:payment_methods,id'],

            // Transaction/reference number — required only for card-type methods
            // (checked dynamically in withValidator(), since the set of methods
            // needing it is now admin-managed, not a fixed enum list). Rejected
            // as a duplicate if the same reference was already used elsewhere.
            'payments.*.transaction_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('invoice_payments', 'transaction_number'),
            ],

            'total_amount'       => ['required', 'numeric', 'min:0.01'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.001'],
            // Manual per-line unit price (cashier can override the configured
            // price) — required for every line in "Manual Total" mode, since
            // that mode has no other price source (no auto-config, no batch
            // override, no distribution).
            'items.*.price'      => [
                Rule::requiredIf(fn () => $this->input('pricing_mode') === 'global'),
                'nullable', 'numeric', 'min:0',
            ],

            // Compose-dialog tagging only (display grouping) — never affects
            // pricing/FIFO. Both null for every normal/legacy line.
            'items.*.parent_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.role'              => ['nullable', 'in:oil,bottle,alcohol'],

            // Cache-based override token — set when manager has approved the request
            'override_token'     => ['nullable', 'string', 'uuid'],
        ];
    }

    /** A zero-priced line is only ever legitimate for the Alcohol role (an
     *  operational material, never charged to the customer — see
     *  catalog-sell-dialog's addToInvoice). Oil and Bottle always keep their
     *  real configured price, so any other product sent with an explicit
     *  price of 0 is rejected here, server-side, regardless of what the
     *  client sent. */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $price = $item['price'] ?? null;
                $role  = $item['role'] ?? null;
                if ($price !== null && (float) $price === 0.0 && $role !== 'alcohol') {
                    $validator->errors()->add("items.$index.price", 'سعر الوحدة يجب أن يكون أكبر من صفر لهذا الصنف.');
                }
            }

            // Card-type payment methods (visa/mastercard/bank_card) require a
            // transaction/reference number — which methods count as "card" is
            // now admin-managed (PaymentMethod::CARD_TYPES), not a fixed enum list.
            $payments = (array) $this->input('payments', []);
            $methodIds = array_filter(array_column($payments, 'payment_method_id'));
            if ($methodIds) {
                $cardMethodIds = PaymentMethod::whereIn('id', $methodIds)
                    ->whereIn('type', PaymentMethod::CARD_TYPES)
                    ->pluck('id')->all();

                foreach ($payments as $index => $payment) {
                    $methodId = $payment['payment_method_id'] ?? null;
                    if ($methodId && in_array($methodId, $cardMethodIds, true) && empty($payment['transaction_number'])) {
                        $validator->errors()->add("payments.$index.transaction_number", 'رقم العملية مطلوب لهذه الوسيلة الدفع.');
                    }
                }
            }
        });
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
            'payments.*.payment_method_id.required_with' => 'وسيلة الدفع مطلوبة لكل صف دفع',
            'payments.*.payment_method_id.exists'        => 'وسيلة الدفع المحددة غير موجودة',
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
            'items.*.price.required'          => 'يجب إدخال سعر يدوي لكل صنف في وضع الإجمالي اليدوي.',
        ];
    }
}
