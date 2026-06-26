<?php

namespace App\Modules\Sales\Requests;

use App\Models\Safe;
use Illuminate\Foundation\Http\FormRequest;

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
            'tester_id'          => ['nullable', 'exists:users,id'],
            'date'               => ['required', 'date'],
            'price_type'         => ['required', 'in:wholesale,retail'],
            'safe_id'            => ['nullable', 'integer', 'exists:safes,id'],

            // Payment breakdown — required for physical safes
            'payments'               => [$isPhysical ? 'required' : 'nullable', 'array', 'min:1'],
            'payments.*.currency_id' => ['required_with:payments', 'integer', 'exists:currencies,id'],
            'payments.*.amount'      => ['required_with:payments', 'numeric', 'min:0.01'],

            'total_amount'       => ['required', 'numeric', 'min:0.01'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'numeric', 'min:0.001'],

            // Cache-based override token — set when manager has approved the request
            'override_token'     => ['nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
          
            'name.max'                        => 'اسم العميل طويل جداً',
          
            'phone.max'                       => 'رقم الهاتف طويل جداً',
            'tester_id.exists'                => 'المراجع المحدد غير موجود في النظام',
            'date.required'                   => 'تاريخ الفاتورة مطلوب',
            'date.date'                       => 'صيغة التاريخ غير صحيحة',
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
