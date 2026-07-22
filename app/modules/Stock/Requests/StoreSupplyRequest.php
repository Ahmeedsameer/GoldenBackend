<?php

namespace App\Modules\Stock\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'          => 'required|integer|exists:suppliers,id',
            'payment_method'       => 'required|in:debt,immediate',
            'items'                => 'required|array|min:1',
            // Compound Products are virtual (composed at sale time) — they
            // have no inventory and can never be purchased/supplied.
            'items.*.product_id'   => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('product_type', '!=', Product::TYPE_COMPOUND)),
            ],
            'items.*.quantity'     => 'required|numeric|min:0.001',
            'items.*.unit_price'   => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required'        => 'يجب تحديد المورد',
            'supplier_id.exists'          => 'المورد المحدد غير موجود في النظام',
            'payment_method.required'     => 'طريقة الدفع مطلوبة',
            'payment_method.in'           => 'طريقة الدفع يجب أن تكون آجل أو فوري',
            'items.required'              => 'يجب إضافة صنف واحد على الأقل',
            'items.array'                 => 'بيانات الأصناف غير صحيحة',
            'items.min'                   => 'يجب إضافة صنف واحد على الأقل',
            'items.*.product_id.required' => 'يجب تحديد المنتج لكل صنف',
            'items.*.product_id.exists'   => 'أحد المنتجات المحددة غير موجود في النظام أو هو منتج مركّب (عطر) لا يمكن توريده — العطور المركّبة منتجات افتراضية تُكوَّن وقت البيع فقط.',
            'items.*.quantity.required'   => 'الكمية مطلوبة لكل صنف',
            'items.*.quantity.numeric'    => 'الكمية يجب أن تكون رقماً',
            'items.*.quantity.min'        => 'الكمية يجب أن تكون أكبر من صفر',
            'items.*.unit_price.required' => 'سعر الوحدة مطلوب لكل صنف',
            'items.*.unit_price.numeric'  => 'سعر الوحدة يجب أن يكون رقماً',
            'items.*.unit_price.min'      => 'سعر الوحدة لا يمكن أن يكون سالباً',
        ];
    }
}
