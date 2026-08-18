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
            // Purchase-invoice fields — Supply IS the invoice (see Supply model).
            'invoice_number'       => 'nullable|string|max:100|unique:supplies,invoice_number',
            'discount'             => 'nullable|numeric|min:0',
            'tax'                  => 'nullable|numeric|min:0',
            // Only meaningful (and only read) when payment_method=immediate —
            // which Safe actually pays the invoice in full right now.
            'safe_id'              => 'nullable|integer|exists:safes,id',
            'currency_id'          => 'nullable|integer|exists:currencies,id',
            'items'                => 'required|array|min:1',
            // Compound Products are virtual (composed at sale time) — they
            // have no inventory and can never be purchased/supplied.
            'items.*.product_id'   => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('product_type', '!=', Product::TYPE_COMPOUND)),
            ],
            'items.*.quantity'     => 'required|numeric|min:0.001',
            'items.*.unit_price'   => 'required|numeric|min:0',
            // The unit the supplier's invoice is actually denominated in —
            // NOT necessarily the product's inventory unit (its `scalar`).
            // The cashier can buy oil by the kilogram even though stock is
            // tracked in grams; SupplyService::create() converts both quantity
            // and unit_price into the product's real inventory unit before
            // anything is written to SupplyItem/Goods. Compatibility with the
            // product's own scalar (g↔kg, ml↔l, pcs only for pcs) is enforced
            // below in withValidator(), since it depends on each product's type.
            'items.*.unit'         => 'required|in:g,kg,ml,l,pcs',
            // Packaging only, optional — lets the first-ever supply of a bottle
            // record its physical capacity right when it's received, instead of
            // requiring a separate Product Management edit beforehand. Never
            // overwrites an existing value (see SupplyService::create).
            'items.*.capacity_ml'  => 'nullable|numeric|min:0.001',
        ];
    }

    /** Cross-field check that can't be expressed as a static rule: the chosen
     *  unit must be dimensionally compatible with the product's own inventory
     *  unit (scalar) — a piece-counted product can't be bought "by the litre". */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $items = (array) $this->input('items', []);
            $productIds = array_filter(array_column($items, 'product_id'));
            if (empty($productIds)) {
                return;
            }
            $scalars = Product::whereIn('id', $productIds)->pluck('scalar', 'id');

            $compatible = [
                'g'   => ['g', 'kg'],
                'ml'  => ['ml', 'l'],
                'pcs' => ['pcs'],
            ];

            foreach ($items as $index => $item) {
                $scalar = $scalars[$item['product_id'] ?? null] ?? null;
                $unit = $item['unit'] ?? null;
                if ($scalar === null || $unit === null) {
                    continue; // already caught by other rules
                }
                $allowed = $compatible[$scalar] ?? [$scalar];
                if (! in_array($unit, $allowed, true)) {
                    $validator->errors()->add("items.$index.unit", 'الوحدة المختارة لا تتوافق مع وحدة تخزين هذا المنتج.');
                }
            }
        });
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
            'items.*.unit.required'       => 'وحدة الشراء مطلوبة لكل صنف',
            'items.*.unit.in'             => 'وحدة الشراء غير مدعومة',
        ];
    }
}
