<?php

namespace App\Modules\Stock\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplyService
{
    public function __construct(private InventoryAlertService $alerts) {}

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
     */
    public function create(array $data): Supply
    {
        $supply = DB::transaction(function () use ($data) {
            // 1. إنشاء سجل التوريد — التاريخ يُولَّد دائماً من الخادم (لا يُدخله المستخدم)
            $today = now()->toDateString();
            $supply = Supply::create([
                'supplier_id'    => $data['supplier_id'],
                'date'           => $today,
                'payment_method' => $data['payment_method'],
            ]);

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

            return $supply->load(['supplier:id,name,phone', 'items.product:id,name,scalar']);
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
     * حذف التوريد — يُمنع إن كانت بضاعته قد نُقلت جزئياً إلى فروع.
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

            $supply->delete();
        });
    }
}
