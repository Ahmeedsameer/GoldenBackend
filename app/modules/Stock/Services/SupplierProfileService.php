<?php

namespace App\Modules\Stock\Services;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Powers the Supplier Profile page — read-only aggregates over
 * Supply/SupplyItem, the same data Supplier Intelligence (inside the Supply
 * screen) already reads. Never writes, never touches Purchasing/FIFO.
 */
class SupplierProfileService
{
    private function base(int $supplierId)
    {
        return DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->where('s.supplier_id', $supplierId);
    }

    public function profile(Supplier $supplier): array
    {
        $totals = $this->base($supplier->id)
            ->selectRaw('COUNT(DISTINCT s.id) as total_purchases, SUM(si.quantity * si.unit_price) as total_value, SUM(si.quantity) as total_qty, AVG(si.unit_price) as avg_price')
            ->first();

        $latest = $this->base($supplier->id)
            ->orderByDesc('s.date')->orderByDesc('si.id')
            ->value('si.unit_price');

        return [
            'general' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'notes' => $supplier->notes,
            ],
            'statistics' => [
                'total_purchases' => (int) ($totals->total_purchases ?? 0),
                'total_purchase_value' => round((float) ($totals->total_value ?? 0), 2),
                'total_purchased_quantity' => round((float) ($totals->total_qty ?? 0), 3),
                'average_purchase_price' => $totals && $totals->total_qty > 0 ? round((float) $totals->total_value / (float) $totals->total_qty, 2) : null,
                'latest_purchase_price' => $latest !== null ? (float) $latest : null,
            ],
        ];
    }

    /** Every product this supplier has supplied, grouped by type. Compound
     *  Products never appear — they are never purchased, so they can never
     *  have a supply_items row in the first place. */
    public function products(Supplier $supplier): array
    {
        $rows = $this->base($supplier->id)
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->selectRaw('
                p.id, p.name, p.sku, p.product_type,
                COUNT(*) as purchase_count,
                SUM(si.quantity) as total_qty,
                AVG(si.unit_price) as avg_price,
                MAX(s.date) as last_purchased_at
            ')
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.product_type')
            ->orderByDesc('purchase_count')
            ->get();

        $grouped = ['RAW_MATERIAL' => [], 'PACKAGING' => [], 'READY_PRODUCT' => []];
        foreach ($rows as $r) {
            $entry = [
                'id' => $r->id, 'name' => $r->name, 'sku' => $r->sku,
                'purchase_count' => (int) $r->purchase_count,
                'total_purchased_quantity' => round((float) $r->total_qty, 3),
                'average_purchase_price' => round((float) $r->avg_price, 2),
                'last_purchased_at' => $r->last_purchased_at,
            ];
            if (isset($grouped[$r->product_type])) {
                $grouped[$r->product_type][] = $entry;
            }
        }

        return $grouped;
    }

    public function analytics(Supplier $supplier): array
    {
        $mostPurchasedCategory = $this->base($supplier->id)
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->selectRaw('c.name, SUM(si.quantity * si.unit_price) as value')
            ->groupBy('c.name')
            ->orderByDesc('value')
            ->first();

        $mostPurchasedProduct = $this->base($supplier->id)
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->selectRaw('p.name, SUM(si.quantity) as qty')
            ->groupBy('p.name')
            ->orderByDesc('qty')
            ->first();

        $monthlyValue = $this->base($supplier->id)
            ->selectRaw("DATE_FORMAT(s.date, '%Y-%m') as month, SUM(si.quantity * si.unit_price) as value, COUNT(DISTINCT s.id) as purchase_count")
            ->groupBy('month')->orderBy('month')
            ->get();

        $priceTrend = $this->base($supplier->id)
            ->selectRaw("DATE_FORMAT(s.date, '%Y-%m') as month, AVG(si.unit_price) as avg_price")
            ->groupBy('month')->orderBy('month')
            ->get();

        return [
            'most_purchased_category' => $mostPurchasedCategory?->name,
            'most_purchased_product' => $mostPurchasedProduct?->name,
            'monthly_purchase_value' => $monthlyValue,
            'purchase_frequency' => $monthlyValue->count() > 0 ? round($monthlyValue->sum('purchase_count') / $monthlyValue->count(), 1) : null,
            'price_trend' => $priceTrend,
        ];
    }

    /** Cross-supplier comparison — informational only, shown on the supplier list. */
    public function globalInsights(): array
    {
        $bestByType = [];
        foreach ([Product::TYPE_RAW_MATERIAL, Product::TYPE_PACKAGING, Product::TYPE_READY_PRODUCT] as $type) {
            $best = DB::table('supply_items as si')
                ->join('supplies as s', 's.id', '=', 'si.supply_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
                ->where('p.product_type', $type)
                ->selectRaw('sup.id, sup.name, SUM(si.quantity * si.unit_price) as value')
                ->groupBy('sup.id', 'sup.name')
                ->orderByDesc('value')
                ->first();
            $bestByType[$type] = $best ? ['id' => $best->id, 'name' => $best->name, 'total_value' => round((float) $best->value, 2)] : null;
        }

        $perSupplier = DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->selectRaw('
                sup.id, sup.name,
                COUNT(*) as purchase_count,
                SUM(si.quantity) as total_qty,
                AVG(si.unit_price) as avg_price,
                STDDEV(si.unit_price) as price_stddev,
                MAX(s.date) as latest_date
            ')
            ->groupBy('sup.id', 'sup.name')
            ->get();

        $lowestAvgPrice   = $perSupplier->sortBy('avg_price')->first();
        $highestVolume    = $perSupplier->sortByDesc('total_qty')->first();
        $mostFrequent     = $perSupplier->sortByDesc('purchase_count')->first();
        $latestSupplier   = $perSupplier->sortByDesc('latest_date')->first();
        $mostStablePricing = $perSupplier->filter(fn ($s) => $s->price_stddev !== null)->sortBy('price_stddev')->first();

        $pick = fn ($s, string $valueKey, ?string $suffix = null) => $s ? [
            'id' => $s->id, 'name' => $s->name,
            'value' => $suffix ? round((float) $s->$valueKey, 2) . $suffix : round((float) $s->$valueKey, 2),
        ] : null;

        return [
            'best_supplier_by_type' => $bestByType,
            'lowest_average_price' => $pick($lowestAvgPrice, 'avg_price'),
            'highest_purchase_volume' => $pick($highestVolume, 'total_qty'),
            'most_frequently_used' => $mostFrequent ? ['id' => $mostFrequent->id, 'name' => $mostFrequent->name, 'value' => (int) $mostFrequent->purchase_count] : null,
            'latest_supplier' => $latestSupplier ? ['id' => $latestSupplier->id, 'name' => $latestSupplier->name, 'value' => $latestSupplier->latest_date] : null,
            'most_stable_pricing' => $mostStablePricing ? ['id' => $mostStablePricing->id, 'name' => $mostStablePricing->name, 'value' => round((float) $mostStablePricing->price_stddev, 2)] : null,
        ];
    }
}
