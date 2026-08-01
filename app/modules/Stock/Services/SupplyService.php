<?php

namespace App\Modules\Stock\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\Safe;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\User;
use App\Modules\Safe\Services\SafeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplyService
{
    public function __construct(
        private InventoryAlertService $alerts,
        private SupplierPaymentService $payments,
        private SafeService $safeService,
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Supply::query()
            ->with(['supplier:id,name,phone', 'items.product:id,name,scalar'])
            ->withCount('items');

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Supplier analytics for a single product type — helps the user pick the
     * right supplier while creating a supply. Read-only, no writes.
     */
    public function supplierIntelligence(string $productType): array
    {
        $base = fn () => DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->where('p.product_type', $productType);

        $topSuppliers = $base()
            ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->selectRaw('sup.id, sup.name, COUNT(*) as purchase_count, SUM(si.quantity * si.unit_price) as total_spent')
            ->groupBy('sup.id', 'sup.name')
            ->orderByDesc('total_spent')
            ->limit(5)
            ->get();

        $mostFrequentSupplier = $base()
            ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->selectRaw('sup.id, sup.name, COUNT(*) as purchase_count')
            ->groupBy('sup.id', 'sup.name')
            ->orderByDesc('purchase_count')
            ->first();

        $mostPurchasedProducts = DB::table('supply_items as si')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->where('p.product_type', $productType)
            ->selectRaw('p.id, p.name, SUM(si.quantity) as total_qty, COUNT(*) as purchase_count')
            ->groupBy('p.id', 'p.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        $totals = $base()->selectRaw('SUM(si.quantity * si.unit_price) as total_cost, SUM(si.quantity) as total_qty, COUNT(*) as purchase_count')->first();
        $averagePrice = ($totals && (float) $totals->total_qty > 0)
            ? round((float) $totals->total_cost / (float) $totals->total_qty, 2)
            : null;

        $latest = $base()
            ->orderByDesc('s.date')
            ->orderByDesc('si.id')
            ->select('si.unit_price', 'p.name as product_name', 's.date')
            ->first();

        return [
            'top_suppliers' => $topSuppliers,
            'most_purchased_products' => $mostPurchasedProducts,
            'average_purchase_price' => $averagePrice,
            'latest_purchase_price' => $latest?->unit_price !== null ? (float) $latest->unit_price : null,
            'latest_purchase_product' => $latest?->product_name,
            'most_frequent_supplier' => $mostFrequentSupplier,
            'purchase_count' => (int) ($totals->purchase_count ?? 0),
        ];
    }

    public function findOrFail(int $id): Supply
    {
        return Supply::with([
            'supplier:id,name,phone',
            'items.product:id,name,scalar',
            'items.goods',
        ])->findOrFail($id);
    }

    /** Purchase-unit → inventory-unit (Product.scalar) conversion factors.
     *  The supplier's invoice can be denominated in a larger unit than what
     *  stock is tracked in (oil bought by the kilogram, stock tracked in
     *  grams) — quantity is multiplied and unit_price divided by this factor
     *  so total cost (quantity × unit_price) is always preserved exactly. */
    private const UNIT_FACTOR = ['g' => 1, 'kg' => 1000, 'ml' => 1, 'l' => 1000, 'pcs' => 1];

    /**
     * إنشاء توريد مع أصنافه وإضافتها تلقائياً إلى المستودع الرئيسي.
     *
     * A Supply row IS a purchase invoice — `invoice_number` is auto-generated
     * (same insert-then-update pattern as TransferRequest's request_number)
     * unless the admin supplies their own. `discount`/`tax` are stored as
     * entered; total/remaining are always derived (see Supply model).
     *
     * If `payment_method === 'immediate'` and a `safe_id` is given, the full
     * invoice total is paid in full right here, through the exact same
     * SupplierPaymentService (and therefore SafeService) an admin would use
     * from the supplier's ledger later — closing the gap where "immediate"
     * used to be a label with no actual money movement. `payment_method`
     * itself is otherwise no longer read — `payment_status` (derived from
     * paid_amount) is now the real source of truth.
     */
    public function create(array $data, \App\Models\User $user): Supply
    {
        $supply = DB::transaction(function () use ($data, $user) {
            // 1. إنشاء سجل التوريد — التاريخ يُولَّد دائماً من الخادم (لا يُدخله المستخدم)
            $today = now()->toDateString();
            $supply = Supply::create([
                'supplier_id'    => $data['supplier_id'],
                'date'           => $today,
                'payment_method' => $data['payment_method'],
                'invoice_number' => $data['invoice_number'] ?? ('PUR-' . now()->format('Ymd') . '-000000'),
                'discount'       => $data['discount'] ?? 0,
                'tax'            => $data['tax'] ?? 0,
            ]);
            if (empty($data['invoice_number'])) {
                $supply->update(['invoice_number' => 'PUR-' . now()->format('Ymd') . '-' . str_pad((string) $supply->id, 4, '0', STR_PAD_LEFT)]);
            }

            // 2. إنشاء كل صنف وإضافة كميته إلى المستودع الرئيسي تلقائياً
            foreach ($data['items'] as $item) {
                // Convert whatever unit the supplier's invoice used (e.g. kg,
                // litre) into the product's real inventory unit — Goods and
                // SupplyItem always store quantity/cost in that base unit,
                // regardless of what the admin picked when receiving stock.
                $factor = self::UNIT_FACTOR[$item['unit'] ?? null] ?? 1;
                $baseQuantity = round($item['quantity'] * $factor, 3);
                $baseUnitPrice = round($item['unit_price'] / $factor, 2);

                $supplyItem = SupplyItem::create([
                    'supply_id'  => $supply->id,
                    'product_id' => $item['product_id'],
                    'quantity'   => $baseQuantity,
                    'unit_price' => $baseUnitPrice,
                ]);

                // التخزين التلقائي في المستودع الرئيسي (shop_id = null)
                Goods::create([
                    'supply_item_id'   => $supplyItem->id,
                    'shop_id'          => null,
                    'current_quantity' => $baseQuantity,
                    'date'             => $today,
                ]);

                // First-ever supply of a bottle can record its capacity right
                // here — never overwrites a value Product Management already
                // set (same "first-fill, never lock" pattern as Default Oil).
                if (! empty($item['capacity_ml'])) {
                    Product::where('id', $item['product_id'])
                        ->whereNull('capacity_ml')
                        ->update(['capacity_ml' => $item['capacity_ml']]);
                }
            }

            $supply->load(['supplier:id,name,phone', 'items.product:id,name,scalar']);

            // "Immediate" now actually pays — the full invoice total, right
            // now, from whichever Safe the admin picked. Only when a safe_id
            // was actually given: an admin who picks "immediate" without
            // choosing a safe is just leaving payment_method as a label (old
            // behavior), same as picking "debt" — they can still pay properly
            // later from the supplier's ledger, which uses this identical path.
            if (($data['payment_method'] ?? null) === 'immediate' && !empty($data['safe_id'])) {
                $safe = Safe::findOrFail($data['safe_id']);
                $currencyId = $data['currency_id'] ?? \App\Models\Currency::where('code', 'EGP')->value('id');
                $this->payments->pay($supply, $safe, (int) $currencyId, $supply->total_amount, $user, 'دفع فوري عند التوريد');
                $supply->refresh();
            }

            return $supply;
        });

        // Supplies land in the main warehouse (shop_id = null) — refresh alert
        // state for each supplied product so a resolved shortage clears.
        foreach (array_unique(array_column($data['items'], 'product_id')) as $pid) {
            $this->alerts->evaluate((int) $pid, null);
        }

        return $supply;
    }

    /**
     * تحديث بيانات التوريد الرئيسية فقط.
     * الأصناف لا تُعدَّل بعد الإنشاء لضمان سلامة سجلات المخزون.
     */
    public function update(Supply $supply, array $data): Supply
    {
        $supply->update($data);
        return $supply->fresh(['supplier:id,name,phone', 'items.product:id,name,scalar']);
    }

    /**
     * حذف التوريد — يُمنع إن كانت بضاعته قد نُقلت جزئياً إلى فروع، أو إن كانت
     * أي دفعة (SupplyItem) منه قد ظهرت بالفعل في فاتورة بيع — الفواتير
     * القديمة يجب أن تستطيع دائماً تحديد الدفعة التي بيعت منها (Batch ID)،
     * فهذا فحص صريح ومباشر على invoice_items.supply_item_id، إضافة إلى قيد
     * قاعدة البيانات نفسه (restrictOnDelete) الذي يمنع هذا فعلياً حتى لو
     * تجوّز كود التطبيق يوماً ما هذا الفحص.
     * الـ cascade يحذف supply_items ثم goods تلقائياً.
     */
    public function delete(Supply $supply): void
    {
        DB::transaction(function () use ($supply) {
            $hasTransferred = $supply->items()
                ->whereHas('goods', fn($q) => $q->whereNotNull('shop_id'))
                ->exists();

            if ($hasTransferred) {
                abort(422, 'لا يمكن حذف التوريد لأن جزءاً من بضاعته قد نُقل إلى الفروع');
            }

            $hasInvoiceHistory = $supply->items()
                ->whereHas('invoiceItems')
                ->exists();

            if ($hasInvoiceHistory) {
                abort(422, 'لا يمكن حذف التوريد — إحدى دفعاته مرتبطة بفواتير بيع فعلية. يمكن أرشفة الدفعة بدلاً من ذلك.');
            }

            $supply->delete();
        });
    }

    /**
     * إلغاء فاتورة توريد — بدل الحذف النهائي: تُعلَّم كملغاة، تُصفَّر كمية
     * البضاعة التي أضافتها في المستودع الرئيسي، وتُسترجع أي دفعة سُددت
     * للمورد عليها إلى نفس الخزنة/العملة التي خرجت منها.
     *
     * يُمنع الإلغاء إذا نُقل جزء من البضاعة إلى الفروع (نفس فحص delete())
     * أو إذا استُهلك جزء منها في المستودع نفسه (بيع/هالك) — أي إن أصبحت
     * الكمية المتبقية أقل مما أضافه هذا التوريد، فلا يوجد شيء آمن نعكسه.
     */
    public function cancel(Supply $supply, User $user): Supply
    {
        return DB::transaction(function () use ($supply, $user) {
            if ($supply->cancelled_at) {
                abort(422, 'الفاتورة ملغاة بالفعل');
            }

            $supply->load(['items.product:id,name', 'items.goods', 'payments.safe.shop:id,name']);

            foreach ($supply->items as $item) {
                $hasTransferred = $item->goods->contains(fn (Goods $g) => $g->shop_id !== null);
                if ($hasTransferred) {
                    abort(422, "لا يمكن إلغاء الفاتورة — جزء من صنف \"{$item->product->name}\" نُقل بالفعل إلى الفروع");
                }

                $warehouseGoods = $item->goods->firstWhere('shop_id', null);
                $onHand = $warehouseGoods ? (float) $warehouseGoods->current_quantity : 0.0;
                if (round($onHand, 3) < round((float) $item->quantity, 3)) {
                    abort(422, "لا يمكن إلغاء الفاتورة — جزء من صنف \"{$item->product->name}\" تم استهلاكه بالفعل (بيع/هالك) من المستودع");
                }
            }

            foreach ($supply->items as $item) {
                $warehouseGoods = $item->goods->firstWhere('shop_id', null);
                $warehouseGoods?->update(['current_quantity' => 0]);
            }

            foreach ($supply->payments as $payment) {
                // Reverse exactly what left the safe: amount + fee, both folded into
                // the single 'supplier_payment' transaction at pay() time (see
                // SupplierPaymentService::pay()) — never just the base amount.
                $this->safeService->recordSupplierPaymentRefund(
                    $payment->safe,
                    $payment,
                    $payment->currency_id,
                    round((float) $payment->amount + (float) $payment->processing_fee_amount, 2),
                    $user->id,
                    "استرجاع دفعة بسبب إلغاء فاتورة التوريد {$supply->invoice_number}",
                    $payment->payment_method_id
                );
            }

            $supply->update([
                'paid_amount'  => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            return $supply->fresh(['supplier:id,name,phone', 'items.product:id,name,scalar', 'cancelledBy:id,name']);
        });
    }
}
