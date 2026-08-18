<?php

namespace App\Modules\BranchOperations\Services;

use App\Services\WarehouseResolver;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for every inventory-affecting event in the
 * ERP — Purchases, Sales, Transfers (in/out), Waste, and Inventory
 * Adjustments (split into "count-driven" vs "manual" using the same origin
 * signal established in the Phase 4.10 Adjustment Reports). Every other
 * stock-movement-shaped report (4.11 Stock Movement Report, 4.12 Inventory
 * Ledger, 4.14 Inventory Audit Report) calls THIS service and only differs
 * in how it presents the same rows — never a second query against the
 * underlying tables.
 *
 * Each source table is normalized into the same 12 columns via one
 * UNION ALL query, so "one source of truth" is enforced structurally, not
 * just by convention:
 *   movement_date, movement_type, reference_number, product_id, shop_id,
 *   source_shop_id, destination_shop_id, quantity_in, quantity_out,
 *   unit_cost, total_value, user_id, notes
 *
 * shop_id follows the existing warehouse convention used across the ERP
 * (AdminStockIntelligenceController::applyLocationFilter): NULL = main
 * warehouse, otherwise a specific shop.
 */
class StockMovementService
{
    public const TYPES = [
        'purchase', 'sale', 'transfer_in', 'transfer_out', 'waste',
        'adjustment_positive', 'adjustment_negative', 'count_adjustment',
    ];

    public function __construct(private WarehouseResolver $warehouse) {}

    /**
     * @param array{from?:string,to?:string,shop_id?:int|string|null,product_id?:int,category_id?:int,supplier_id?:int} $filters
     * shop_id: null/absent = all locations, 0 OR the real Main Warehouse shop id = warehouse only, N = specific shop
     * (Phase 5.2 — the legacy 0 sentinel and the real warehouse Shop id both resolve to the same NULL-shop rows via WarehouseResolver).
     * @return \Illuminate\Support\Collection Normalized movement rows, oldest first.
     */
    public function movements(array $filters): \Illuminate\Support\Collection
    {
        // The UNION SQL contains no user input (see unionSql) — every filter below is
        // applied as a parameterized where()/whereIn() against the wrapped subquery.
        $sql = $this->unionSql();
        $query = DB::table(DB::raw("({$sql}) as m"));

        if (! empty($filters['from'])) {
            $query->where('m.movement_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('m.movement_date', '<=', $filters['to'] . ' 23:59:59');
        }
        if (array_key_exists('shop_id', $filters) && $filters['shop_id'] !== null && $filters['shop_id'] !== '') {
            $shopId = (int) $filters['shop_id'];
            $goodsShopId = $shopId === 0 ? null : $this->warehouse->goodsShopId($shopId);
            $goodsShopId === null ? $query->whereNull('m.shop_id') : $query->where('m.shop_id', $goodsShopId);
        }
        if (! empty($filters['product_id'])) {
            $query->where('m.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['category_id'])) {
            $categoryProductIds = DB::table('products')->where('category_id', (int) $filters['category_id'])->pluck('id');
            $query->whereIn('m.product_id', $categoryProductIds);
        }
        if (! empty($filters['supplier_id'])) {
            $supplierProductIds = DB::table('supply_items as si')
                ->join('supplies as s', 's.id', '=', 'si.supply_id')
                ->where('s.supplier_id', (int) $filters['supplier_id'])
                ->distinct()->pluck('si.product_id');
            $query->whereIn('m.product_id', $supplierProductIds);
        }

        $rows = $query->orderBy('m.movement_date')->orderBy('m.movement_type')->orderBy('m.source_id')->get();

        // Attach product/shop display names + user names in one batch each (no N+1).
        return $this->hydrate($rows);
    }

    /** Sum of (in - out) for everything strictly before $before, under the same filters minus the date range — the ledger's opening balance. */
    public function openingBalance(array $filters, string $before): float
    {
        $filters['to'] = null;
        unset($filters['from']);
        $rows = $this->movements($filters)->filter(fn ($r) => $r->movement_date < $before);

        return round($rows->sum('quantity_in') - $rows->sum('quantity_out'), 3);
    }

    private function unionSql(): string
    {
        $parts = [
            // Purchases — ONE row per supply_item, never N rows for a batch later
            // transferred to N shops. Prefers the original warehouse-landing Goods
            // row (shop_id IS NULL) when one exists; falls back to that
            // supply_item's own earliest Goods row when it doesn't. That fallback
            // matters: some supply_items (verified — 195 of 309 in this dataset,
            // mostly from historical/seeded "opening stock" at a branch) were
            // stocked directly into a shop with NO warehouse leg at all. The old
            // query's unconditional `g.shop_id IS NULL` join silently dropped every
            // one of these purchases from the audit entirely — no inflow was ever
            // recorded for them, so every later sale against that stock understated
            // the running balance, which is exactly why old/new quantity showed up
            // negative in the report despite real stock never having gone negative.
            // `g.shop_id` (not a hardcoded NULL) also now correctly attributes the
            // purchase to wherever it actually landed, so it nets against sales in
            // the SAME (product, shop) balance instead of a warehouse group that
            // may have no other activity for that product at all.
            "SELECT sup.date as movement_date, 'purchase' as movement_type,
                    CONCAT('SUP-', sup.id) as reference_number, si.id as source_id,
                    si.product_id as product_id, g.shop_id as shop_id, NULL as source_shop_id, NULL as destination_shop_id,
                    si.quantity as quantity_in, 0 as quantity_out, si.unit_price as unit_cost,
                    (si.quantity * si.unit_price) as total_value, NULL as user_id, NULL as notes
             FROM supply_items si
             JOIN supplies sup ON sup.id = si.supply_id
             JOIN goods g ON g.id = (
                 SELECT g2.id FROM goods g2
                 WHERE g2.supply_item_id = si.id
                 ORDER BY (g2.shop_id IS NULL) DESC, g2.date ASC, g2.id ASC
                 LIMIT 1
             )",

            // Sales — every invoice_items row already represents real consumption of its
            // own product_id (oil/bottle sub-lines included), see Phase 3B compound-usage.
            "SELECT inv.date as movement_date, 'sale' as movement_type,
                    CONCAT('INV-', inv.id) as reference_number, ii.id as source_id,
                    ii.product_id as product_id, inv.shop_id as shop_id, NULL as source_shop_id, NULL as destination_shop_id,
                    0 as quantity_in, ii.quantity as quantity_out, ii.price as unit_cost,
                    (ii.quantity * ii.price) as total_value, inv.seller_id as user_id, NULL as notes
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id
             WHERE inv.status = 'approved'",

            // Transfer Out — FIFO batches actually shipped (see TransferRequestService::ship).
            "SELECT tr.shipped_at as movement_date, 'transfer_out' as movement_type,
                    tr.request_number as reference_number, trib.id as source_id,
                    tri.product_id as product_id, tr.source_shop_id as shop_id,
                    tr.source_shop_id as source_shop_id, tr.destination_shop_id as destination_shop_id,
                    0 as quantity_in, trib.quantity_shipped as quantity_out, NULL as unit_cost, NULL as total_value,
                    tr.requested_by as user_id, NULL as notes
             FROM transfer_request_item_batches trib
             JOIN transfer_request_items tri ON tri.id = trib.transfer_request_item_id
             JOIN transfer_requests tr ON tr.id = tri.transfer_request_id
             WHERE tr.shipped_at IS NOT NULL",

            // Transfer In — the same batches, credited on arrival (see TransferRequestService::receive).
            "SELECT tr.received_at as movement_date, 'transfer_in' as movement_type,
                    tr.request_number as reference_number, trib.id as source_id,
                    tri.product_id as product_id, tr.destination_shop_id as shop_id,
                    tr.source_shop_id as source_shop_id, tr.destination_shop_id as destination_shop_id,
                    trib.quantity_received as quantity_in, 0 as quantity_out, NULL as unit_cost, NULL as total_value,
                    tr.requested_by as user_id, NULL as notes
             FROM transfer_request_item_batches trib
             JOIN transfer_request_items tri ON tri.id = trib.transfer_request_item_id
             JOIN transfer_requests tr ON tr.id = tri.transfer_request_id
             WHERE tr.received_at IS NOT NULL AND trib.quantity_received > 0",

            // Waste.
            "SELECT w.date as movement_date, 'waste' as movement_type,
                    CONCAT('WR-', w.id) as reference_number, w.id as source_id,
                    w.product_id as product_id, w.shop_id as shop_id, NULL as source_shop_id, NULL as destination_shop_id,
                    0 as quantity_in, w.quantity as quantity_out, NULL as unit_cost, w.estimated_value as total_value,
                    w.user_id as user_id, w.reason as notes
             FROM waste_records w",

            // Inventory Adjustments — split into count-driven vs manual (same signal as
            // Phase 4.10's "by reason"), and manual ones further split +/- for the report's
            // "Adjustment (+)" / "Adjustment (-)" rows.
            "SELECT iar.created_at as movement_date,
                    CASE WHEN iar.inventory_count_session_id IS NOT NULL THEN 'count_adjustment'
                         WHEN iar.difference >= 0 THEN 'adjustment_positive'
                         ELSE 'adjustment_negative' END as movement_type,
                    CONCAT('ADJ-', iar.id) as reference_number, iar.id as source_id,
                    iar.product_id as product_id, iar.shop_id as shop_id, NULL as source_shop_id, NULL as destination_shop_id,
                    CASE WHEN iar.difference > 0 THEN iar.difference ELSE 0 END as quantity_in,
                    CASE WHEN iar.difference < 0 THEN ABS(iar.difference) ELSE 0 END as quantity_out,
                    NULL as unit_cost, NULL as total_value, iar.requested_by as user_id, iar.reason as notes
             FROM inventory_adjustment_requests iar
             WHERE iar.status = 'executed'",
        ];

        return implode(' UNION ALL ', $parts);
    }

    private function hydrate(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $productIds = $rows->pluck('product_id')->filter()->unique()->values();
        $shopIds = $rows->flatMap(fn ($r) => [$r->shop_id, $r->source_shop_id, $r->destination_shop_id])->filter()->unique()->values();
        $userIds = $rows->pluck('user_id')->filter()->unique()->values();

        $products = DB::table('products')->whereIn('id', $productIds)->get(['id', 'name', 'sku', 'category_id', 'product_type', 'scalar'])->keyBy('id');
        $shops = DB::table('shops')->whereIn('id', $shopIds)->get(['id', 'name'])->keyBy('id');
        $users = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        return $rows->map(function ($r) use ($products, $shops, $users) {
            $product = $products->get($r->product_id);
            $r->product_name = $product->name ?? null;
            $r->product_sku = $product->sku ?? null;
            $r->product_type = $product->product_type ?? null;
            $r->unit = $product->scalar ?? null;
            $r->shop_name = $r->shop_id ? ($shops->get($r->shop_id)->name ?? null) : 'المستودع الرئيسي';
            $r->source_shop_name = $r->source_shop_id ? ($shops->get($r->source_shop_id)->name ?? null) : null;
            $r->destination_shop_name = $r->destination_shop_id ? ($shops->get($r->destination_shop_id)->name ?? null) : null;
            $r->user_name = $r->user_id ? ($users->get($r->user_id)->name ?? null) : null;
            $r->quantity_in = round((float) $r->quantity_in, 3);
            $r->quantity_out = round((float) $r->quantity_out, 3);

            return $r;
        });
    }
}
