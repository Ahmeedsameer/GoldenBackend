<?php

namespace App\Http\Services;

use App\Models\Goods;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SupplyItem;
use Illuminate\Support\Facades\DB;

/**
 * Powers the Product Details page — the single professional profile screen
 * for a product. Read-only: never touches Goods/SupplyItem/FIFO/Invoice
 * writes, only aggregates what Purchasing/Sales/Pricing already produced.
 *
 * Compound Products are virtual (no stock, never purchased) — every method
 * here branches on that and returns sales-derived data instead.
 */
class ProductDetailService
{
    // ── General + summary (the page's header cards) ──────────────────────────

    public function summary(Product $product): array
    {
        $product->loadMissing('category:id,name');
        $isCompound = $product->product_type === Product::TYPE_COMPOUND;

        return [
            'general' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category?->name,
                'product_type' => $product->product_type,
                'image' => $product->image,
                'is_active' => (bool) $product->is_active,
                'notes' => $product->notes,
                'description' => $product->description,
            ],
            'inventory' => $isCompound ? null : $this->inventorySummary($product),
            'sales' => $this->salesSummary($product),
            'compound_usage' => $isCompound ? $this->compoundUsage($product) : null,
        ];
    }

    private function inventorySummary(Product $product): array
    {
        $currentStock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $product->id))
            ->sum('current_quantity');

        // No reservation system exists anywhere in this ERP today (stock is
        // deducted immediately at sale via FIFO, never "held") — reserved is
        // always 0, so available always equals current stock.
        $reserved = 0.0;

        $latestCost = SupplyItem::query()
            ->join('supplies', 'supplies.id', '=', 'supply_items.supply_id')
            ->where('supply_items.product_id', $product->id)
            ->orderByDesc('supplies.date')
            ->orderByDesc('supply_items.id')
            ->value('supply_items.unit_price');
        $unitCost = $product->purchase_cost !== null ? (float) $product->purchase_cost : ($latestCost !== null ? (float) $latestCost : 0.0);

        $lastUpdate = Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $product->id))
            ->max('updated_at');

        return [
            'current_stock' => $currentStock,
            'reserved_quantity' => $reserved,
            'available_quantity' => $currentStock - $reserved,
            'minimum_stock' => $product->warning_quantity !== null ? (float) $product->warning_quantity : null,
            'inventory_value' => round($currentStock * $unitCost, 2),
            'last_inventory_update' => $lastUpdate,
        ];
    }

    private function salesSummary(Product $product): array
    {
        // Ready Products: their own invoice lines. Compound Products: the
        // summarized parent line only (role IS NULL) — never the oil/bottle
        // sub-lines, which is exactly what "show only the product sales,
        // never oil or bottle" means here.
        $query = InvoiceItem::query()
            ->whereNull('role')
            ->where('product_id', $product->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'approved'));

        $row = $query->selectRaw('COUNT(*) as sales_count, SUM(quantity) as total_qty, SUM(quantity * price) as revenue')->first();

        $revenue = (float) ($row->revenue ?? 0);
        $qty = (float) ($row->total_qty ?? 0);

        return [
            'sales_count' => (int) ($row->sales_count ?? 0),
            'total_quantity_sold' => $qty,
            'revenue' => round($revenue, 2),
            'average_selling_price' => $qty > 0 ? round($revenue / $qty, 2) : null,
        ];
    }

    /** Compound Products only — which oils/bottles were actually used, from real sales. */
    private function compoundUsage(Product $product): array
    {
        $base = fn (string $role) => InvoiceItem::query()
            ->where('parent_product_id', $product->id)
            ->where('role', $role)
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', 'approved'));

        $oils = $base('oil')
            ->selectRaw('products.id, products.name, COUNT(*) as times_used, SUM(invoice_items.quantity) as total_qty')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('times_used')
            ->limit(5)
            ->get();

        $bottles = $base('bottle')
            ->selectRaw('products.id, products.name, COUNT(*) as times_used')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('times_used')
            ->limit(5)
            ->get();

        return ['most_used_oils' => $oils, 'most_used_bottles' => $bottles];
    }

    // ── Purchase history (never for Compound) ─────────────────────────────────

    public function purchaseHistory(Product $product, array $filters, int $perPage): mixed
    {
        $q = DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->leftJoin('goods as g', 'g.supply_item_id', '=', 'si.id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'g.shop_id')
            ->where('si.product_id', $product->id)
            ->selectRaw('
                s.id as supply_id, s.date, sup.name as supplier_name,
                si.quantity, si.unit_price, (si.quantity * si.unit_price) as total_cost,
                COALESCE(sh.name, "المستودع الرئيسي") as branch_name
            ');

        if (! empty($filters['search'])) {
            $q->where('sup.name', 'like', '%' . $filters['search'] . '%');
        }

        $sortable = ['date' => 's.date', 'quantity' => 'si.quantity', 'unit_price' => 'si.unit_price', 'total_cost' => 'total_cost'];
        $sortCol = $sortable[$filters['sort'] ?? 'date'] ?? 's.date';
        $sortDir = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortCol, $sortDir)->orderByDesc('si.id');

        return $q->paginate($perPage);
    }

    // ── Inventory movement (Purchase-in + Sale-out — the only two ledgered
    //     event types that actually exist in this schema; Transfer mutates
    //     Goods in place with no history table, so it cannot be listed here
    //     without inventing data) ─────────────────────────────────────────

    public function movements(Product $product, int $perPage, int $page): \Illuminate\Pagination\LengthAwarePaginator
    {
        $purchases = DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->leftJoin('goods as g', 'g.supply_item_id', '=', 'si.id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'g.shop_id')
            ->where('si.product_id', $product->id)
            ->selectRaw('s.date as date, "purchase" as type, si.quantity as quantity, NULL as user_name, COALESCE(sh.name, "المستودع الرئيسي") as branch_name, s.id as ref_id')
            ->get();

        $sales = DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('ii.product_id', $product->id)
            ->whereNull('ii.role')
            ->where('inv.status', 'approved')
            ->selectRaw('inv.date as date, "sale" as type, ii.quantity as quantity, inv.seller_name as user_name, COALESCE(inv.branch_name, "—") as branch_name, inv.id as ref_id')
            ->get();

        $all = $purchases->concat($sales)
            ->sortByDesc(fn ($r) => $r->date . '-' . str_pad((string) $r->ref_id, 10, '0', STR_PAD_LEFT))
            ->values();

        $page = max(1, $page);
        $slice = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator($slice, $all->count(), $perPage, $page);
    }

    // ── Supplier history (never for Compound — they're never supplied) ───────

    public function supplierHistory(Product $product): array
    {
        $rows = DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->join('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->where('si.product_id', $product->id)
            ->selectRaw('
                sup.id, sup.name,
                COUNT(*) as purchase_count,
                SUM(si.quantity) as total_qty,
                AVG(si.unit_price) as avg_price
            ')
            ->groupBy('sup.id', 'sup.name')
            ->orderByDesc('purchase_count')
            ->get();

        $latestBySupplier = DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->where('si.product_id', $product->id)
            ->select('s.supplier_id', 'si.unit_price', 's.date')
            ->orderByDesc('s.date')
            ->orderByDesc('si.id')
            ->get()
            ->unique('supplier_id')
            ->keyBy('supplier_id');

        return $rows->map(fn ($r) => [
            'supplier_id' => $r->id,
            'supplier_name' => $r->name,
            'purchase_count' => (int) $r->purchase_count,
            'total_purchased_quantity' => round((float) $r->total_qty, 3),
            'average_purchase_price' => round((float) $r->avg_price, 2),
            'latest_purchase_price' => isset($latestBySupplier[$r->id]) ? round((float) $latestBySupplier[$r->id]->unit_price, 2) : null,
        ])->values()->all();
    }

    // ── Analytics: monthly trend series for charts ────────────────────────────

    public function analytics(Product $product): array
    {
        $isCompound = $product->product_type === Product::TYPE_COMPOUND;

        $purchaseTrend = $isCompound ? [] : DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->where('si.product_id', $product->id)
            ->selectRaw("DATE_FORMAT(s.date, '%Y-%m') as month, SUM(si.quantity) as qty, SUM(si.quantity * si.unit_price) as value")
            ->groupBy('month')->orderBy('month')
            ->get();

        $salesTrend = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNull('invoice_items.role')
            ->where('invoice_items.product_id', $product->id)
            ->where('invoices.status', 'approved')
            ->selectRaw("DATE_FORMAT(invoices.date, '%Y-%m') as month, SUM(invoice_items.quantity) as qty, SUM(invoice_items.quantity * invoice_items.price) as revenue")
            ->groupBy('month')->orderBy('month')
            ->get();

        $priceTrend = $product->priceHistories()
            ->whereNotNull('new_selling_price')
            ->orderBy('created_at')
            ->get(['new_selling_price', 'created_at'])
            ->map(fn ($h) => ['date' => $h->created_at->toDateString(), 'price' => (float) $h->new_selling_price]);

        return [
            'purchase_trend' => $purchaseTrend,
            'sales_trend' => $salesTrend,
            'price_trend' => $priceTrend,
        ];
    }
}
