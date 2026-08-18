<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
use App\Models\SupplyItem;
use App\Services\Reports\ReportExportService;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.13 — FIFO / Batch Traceability. A "batch" here is one SupplyItem
 * (one purchased lot) — its Goods rows (one per shop it has ever been
 * transferred to) are the FIFO entities already used throughout the ERP.
 * No inventory logic is duplicated: consumption is read back from the same
 * *_batches link tables other phases already write (waste_record_batches,
 * transfer_request_item_batches, and the new inventory_adjustment_batches —
 * see InventoryAdjustmentService::execute(), which already resolved specific
 * Goods rows but never persisted it before this phase, exactly like the
 * Waste/Transfer batch links added in Phase 4.8/4.7).
 *
 * Reserved Quantity is always 0 — there is no reservation system in this ERP
 * (see ProductDetailService::inventorySummary()). Expiry Date is always null
 * — there is no expiry-date tracking on Goods/SupplyItem.
 */
class BatchTraceabilityController extends Controller
{
    public function __construct(private ReportExportService $exportService, private WarehouseResolver $warehouse) {}

    private function filters(Request $request): array
    {
        return [
            'product_id' => $request->filled('product_id') ? (int) $request->get('product_id') : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->get('supplier_id') : null,
            'shop_id' => $request->filled('shop_id') ? $request->get('shop_id') : null,
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'search' => $request->get('search'),
            // 'finished' = remaining_quantity <= 0 (fully consumed/depleted).
            // 'near_finished' = 0 < remaining_quantity <= near_finished_threshold
            // (default 10, same unit as the batch — g/ml/pcs).
            'status' => $request->get('status'),
            'near_finished_threshold' => $request->filled('near_finished_threshold') ? (float) $request->get('near_finished_threshold') : 10.0,
            // 'revenue' | 'profit' | 'sold' | 'remaining' — ranks the FULL
            // filtered dataset before pagination (Profit by Batch / Sales by
            // Batch report views); default stays newest-first.
            'sort' => $request->get('sort'),
        ];
    }

    private function baseQuery(array $filters)
    {
        $query = SupplyItem::with(['supply.supplier', 'product:id,name,sku,product_type'])
            ->select('supply_items.*')
            // Global remaining quantity across every shop this batch's Goods
            // rows exist in — same "sum of all Goods for this batch" the
            // consumption()/mapBatch() pair already computes, just made
            // available in SQL here too so 'finished'/'near_finished' can
            // filter on it without a separate post-fetch pass.
            ->selectSub(
                Goods::query()
                    ->whereColumn('goods.supply_item_id', 'supply_items.id')
                    ->selectRaw('COALESCE(SUM(current_quantity), 0)'),
                'remaining_quantity_calc'
            )
            // Sort-only aggregates (Profit by Batch / Sales by Batch report
            // views) — mapBatch() still computes the actual displayed
            // revenue/profit via consumption()'s single grouped query per
            // page, this is purely so ORDER BY can rank the full dataset
            // before pagination, not just the current page.
            ->selectSub(
                DB::table('invoice_items as ii')
                    ->join('goods as g', 'g.id', '=', 'ii.goods_id')
                    ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                    ->whereColumn('g.supply_item_id', 'supply_items.id')
                    ->where('inv.status', 'approved')
                    ->selectRaw('COALESCE(SUM(ii.quantity * ii.price), 0)'),
                'revenue_calc'
            )
            ->selectSub(
                DB::table('invoice_items as ii')
                    ->join('goods as g', 'g.id', '=', 'ii.goods_id')
                    ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                    ->whereColumn('g.supply_item_id', 'supply_items.id')
                    ->where('inv.status', 'approved')
                    ->selectRaw('COALESCE(SUM(ii.line_profit), 0)'),
                'profit_calc'
            )
            ->selectSub(
                DB::table('invoice_items as ii')
                    ->join('goods as g', 'g.id', '=', 'ii.goods_id')
                    ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                    ->whereColumn('g.supply_item_id', 'supply_items.id')
                    ->where('inv.status', 'approved')
                    ->selectRaw('COALESCE(SUM(ii.quantity), 0)'),
                'sold_calc'
            );

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->whereHas('supply', fn ($q) => $q->where('supplier_id', $filters['supplier_id']));
        }
        if (! empty($filters['from'])) {
            $query->whereHas('supply', fn ($q) => $q->where('date', '>=', $filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->whereHas('supply', fn ($q) => $q->where('date', '<=', $filters['to']));
        }
        if ($filters['shop_id'] !== null && $filters['shop_id'] !== '') {
            $shopId = (int) $filters['shop_id'];
            $goodsShopId = $shopId === 0 ? null : $this->warehouse->goodsShopId($shopId);
            $query->whereHas('goods', fn ($q) => $goodsShopId === null ? $q->whereNull('shop_id') : $q->where('shop_id', $goodsShopId));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('id', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"))
                    ->orWhereHas('supply', fn ($s) => $s->where('id', $term));
            });
        }
        if ($filters['status'] === 'finished') {
            $query->havingRaw('remaining_quantity_calc <= 0');
        } elseif ($filters['status'] === 'near_finished') {
            $query->havingRaw('remaining_quantity_calc > 0 AND remaining_quantity_calc <= ?', [$filters['near_finished_threshold']]);
        }

        $sortColumns = [
            'revenue' => 'revenue_calc',
            'profit' => 'profit_calc',
            'sold' => 'sold_calc',
            'remaining' => 'remaining_quantity_calc',
        ];
        if (! empty($filters['sort']) && isset($sortColumns[$filters['sort']])) {
            return $query->orderByDesc($sortColumns[$filters['sort']]);
        }

        return $query->orderByDesc('id');
    }

    /** Batch consumption totals for a set of supply_item ids, one grouped query per source (no N+1). */
    private function consumption(\Illuminate\Support\Collection $supplyItemIds): array
    {
        $remaining = DB::table('goods')->whereIn('supply_item_id', $supplyItemIds)
            ->groupBy('supply_item_id')->select('supply_item_id', DB::raw('SUM(current_quantity) as qty'))
            ->pluck('qty', 'supply_item_id');

        $transferred = DB::table('transfer_request_item_batches as trib')
            ->join('goods as g', 'g.id', '=', 'trib.goods_id')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(trib.quantity_shipped) as qty'))
            ->pluck('qty', 'supply_item_id');

        $wasted = DB::table('waste_record_batches as wrb')
            ->join('goods as g', 'g.id', '=', 'wrb.goods_id')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(wrb.quantity) as qty'))
            ->pluck('qty', 'supply_item_id');

        $sold = DB::table('invoice_items as ii')
            ->join('goods as g', 'g.id', '=', 'ii.goods_id')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('inv.status', 'approved')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(ii.quantity) as qty'))
            ->pluck('qty', 'supply_item_id');

        $adjusted = DB::table('inventory_adjustment_batches as iab')
            ->join('goods as g', 'g.id', '=', 'iab.goods_id')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(iab.quantity_delta) as qty'))
            ->pluck('qty', 'supply_item_id');

        // Revenue/profit mirror the exact per-line snapshot (InvoiceItem.
        // line_cost/line_profit, frozen at sale time) — never the batch's
        // CURRENT price, so a later batch price edit never rewrites what a
        // batch already earned. One grouped query each, same pattern as
        // every other metric here — never N+1 per row.
        $revenue = DB::table('invoice_items as ii')
            ->join('goods as g', 'g.id', '=', 'ii.goods_id')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('inv.status', 'approved')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(ii.quantity * ii.price) as amount'))
            ->pluck('amount', 'supply_item_id');

        $grossProfit = DB::table('invoice_items as ii')
            ->join('goods as g', 'g.id', '=', 'ii.goods_id')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('inv.status', 'approved')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->groupBy('g.supply_item_id')->select('g.supply_item_id', DB::raw('SUM(ii.line_profit) as amount'))
            ->pluck('amount', 'supply_item_id');

        return compact('remaining', 'transferred', 'wasted', 'sold', 'adjusted', 'revenue', 'grossProfit');
    }

    private function mapBatch(SupplyItem $item, array $c): array
    {
        $soldQty = round((float) ($c['sold'][$item->id] ?? 0), 3);
        $purchaseCost = (float) $item->unit_price;
        $sellingPrice = $item->selling_price !== null ? (float) $item->selling_price : null;
        // Revenue/profit come from consumption()'s grouped queries (one query
        // total across every batch on the page, never one per row) and mirror
        // the exact per-line snapshot (InvoiceItem.line_cost/line_profit,
        // frozen at sale time) — never the batch's CURRENT price, so a later
        // batch price edit never rewrites what a batch already earned.
        $revenue = round((float) ($c['revenue'][$item->id] ?? 0), 2);
        $grossProfit = round((float) ($c['grossProfit'][$item->id] ?? 0), 2);

        return [
            'id' => $item->id,
            'batch_number' => 'BATCH-' . $item->id,
            'purchase_invoice' => 'SUP-' . $item->supply_id,
            'supplier' => $item->supply->supplier->name ?? null,
            'purchase_date' => optional($item->supply)->date,
            'expiry_date' => null,
            'product_name' => $item->product->name ?? null,
            'sku' => $item->product->sku ?? null,
            'original_quantity' => round((float) $item->quantity, 3),
            'remaining_quantity' => round((float) ($c['remaining'][$item->id] ?? 0), 3),
            'reserved_quantity' => 0,
            'transferred_quantity' => round((float) ($c['transferred'][$item->id] ?? 0), 3),
            'wasted_quantity' => round((float) ($c['wasted'][$item->id] ?? 0), 3),
            'sold_quantity' => $soldQty,
            'net_adjustment' => round((float) ($c['adjusted'][$item->id] ?? 0), 3),
            'purchase_cost' => round($purchaseCost, 2),
            'selling_price' => $sellingPrice !== null ? round($sellingPrice, 2) : null,
            'revenue' => $revenue,
            'gross_profit' => $grossProfit,
            'status' => ((float) ($c['remaining'][$item->id] ?? 0)) <= 0 ? 'finished' : 'active',
        ];
    }

    // ── GET /branch-operations/reports/batches/summary ───────────────────────
    public function summary(Request $request)
    {
        $filters = $this->filters($request);
        $ids = $this->baseQuery($filters)->pluck('id');
        $c = $this->consumption($ids);
        $originalTotal = DB::table('supply_items')->whereIn('id', $ids)->sum('quantity');

        return response()->json(['message' => 'ok', 'data' => [
            'total_batches' => $ids->count(),
            'total_original_quantity' => round((float) $originalTotal, 3),
            'total_remaining_quantity' => round((float) $c['remaining']->only($ids->all())->sum(), 3),
            'total_transferred_quantity' => round((float) $c['transferred']->only($ids->all())->sum(), 3),
            'total_wasted_quantity' => round((float) $c['wasted']->only($ids->all())->sum(), 3),
            'total_sold_quantity' => round((float) $c['sold']->only($ids->all())->sum(), 3),
        ]]);
    }

    // ── GET /branch-operations/reports/batches ────────────────────────────────
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $paginator = $this->baseQuery($filters)->paginate((int) $request->get('per_page', 20));
        $ids = collect($paginator->items())->pluck('id');
        $c = $this->consumption($ids);

        $rows = collect($paginator->items())->map(fn ($item) => $this->mapBatch($item, $c))->values();

        return response()->json([
            'message' => 'ok',
            'data' => [
                'rows' => $rows,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
        ]);
    }

    // ── GET /branch-operations/reports/batches/export?format=pdf|excel ──────
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $items = $this->baseQuery($filters)->get();
        $c = $this->consumption($items->pluck('id'));
        $rows = $items->map(fn ($item) => $this->mapBatch($item, $c));

        $columns = ['رقم الدفعة', 'فاتورة الشراء', 'المورّد', 'المنتج', 'الكود', 'تاريخ الشراء', 'الكمية الأصلية', 'المتبقي', 'منقول', 'هالك', 'مباع', 'تكلفة الشراء', 'سعر البيع', 'الإيرادات', 'الربح الإجمالي', 'الحالة'];
        $tableRows = $rows->map(fn ($r) => [
            $r['batch_number'], $r['purchase_invoice'], $r['supplier'], $r['product_name'], $r['sku'],
            $r['purchase_date'], $r['original_quantity'], $r['remaining_quantity'], $r['transferred_quantity'],
            $r['wasted_quantity'], $r['sold_quantity'], $r['purchase_cost'], $r['selling_price'] ?? '—',
            $r['revenue'], $r['gross_profit'], $r['status'] === 'finished' ? 'منتهية' : 'نشطة',
        ])->all();

        $filterLabels = array_filter([
            'من' => $filters['from'], 'إلى' => $filters['to'],
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تتبّع دفعات المخزون (FIFO)', $columns, $tableRows, $filterLabels)
            : $this->exportService->pdf('تتبّع دفعات المخزون (FIFO)', $columns, $tableRows, $filterLabels);
    }

    // ── GET /branch-operations/reports/batches/{supplyItem} ──────────────────
    public function show(SupplyItem $supplyItem)
    {
        $supplyItem->load(['supply.supplier', 'product:id,name,sku,product_type,scalar', 'goods.shop:id,name']);

        $c = $this->consumption(collect([$supplyItem->id]));
        $summary = $this->mapBatch($supplyItem, $c);

        $goodsIds = $supplyItem->goods->pluck('id');

        $locations = $supplyItem->goods->map(fn ($g) => [
            'shop_name' => $g->shop->name ?? 'المستودع الرئيسي',
            'current_quantity' => round((float) $g->current_quantity, 3),
        ])->values();

        $events = collect();

        $events->push([
            'date' => (string) $supplyItem->supply->date,
            'type' => 'purchase', 'type_label' => 'شراء',
            'reference_number' => 'SUP-' . $supplyItem->supply_id,
            'quantity_delta' => round((float) $supplyItem->quantity, 3),
            'shop_name' => null, 'user' => null, 'notes' => null,
        ]);

        DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'inv.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'inv.seller_id')
            ->where('inv.status', 'approved')
            ->whereIn('ii.goods_id', $goodsIds)
            ->select('inv.date as date', 'inv.id as invoice_id', 'ii.quantity as quantity', 'sh.name as shop_name', 'u.name as user_name')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'sale', 'type_label' => 'بيع',
                    'reference_number' => 'INV-' . $r->invoice_id,
                    'quantity_delta' => -round((float) $r->quantity, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name, 'notes' => null,
                ]);
            });

        DB::table('transfer_request_item_batches as trib')
            ->join('transfer_request_items as tri', 'tri.id', '=', 'trib.transfer_request_item_id')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->leftJoin('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->leftJoin('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'tr.requested_by')
            ->whereIn('trib.goods_id', $goodsIds)
            ->select(
                'tr.shipped_at', 'tr.received_at', 'tr.request_number',
                'trib.quantity_shipped', 'trib.quantity_received',
                'src.name as source_shop_name', 'dst.name as destination_shop_name', 'u.name as user_name'
            )
            ->get()->each(function ($r) use ($events) {
                if ($r->shipped_at) {
                    $events->push([
                        'date' => (string) $r->shipped_at, 'type' => 'transfer_out', 'type_label' => 'نقل صادر',
                        'reference_number' => $r->request_number,
                        'quantity_delta' => -round((float) $r->quantity_shipped, 3),
                        'shop_name' => $r->source_shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                        'notes' => 'إلى ' . ($r->destination_shop_name ?? 'المستودع الرئيسي'),
                    ]);
                }
                if ($r->received_at && (float) $r->quantity_received > 0) {
                    $events->push([
                        'date' => (string) $r->received_at, 'type' => 'transfer_in', 'type_label' => 'نقل وارد',
                        'reference_number' => $r->request_number,
                        'quantity_delta' => round((float) $r->quantity_received, 3),
                        'shop_name' => $r->destination_shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                        'notes' => 'من ' . ($r->source_shop_name ?? 'المستودع الرئيسي'),
                    ]);
                }
            });

        DB::table('waste_record_batches as wrb')
            ->join('waste_records as w', 'w.id', '=', 'wrb.waste_record_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'w.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->whereIn('wrb.goods_id', $goodsIds)
            ->select('w.date as date', 'w.id as waste_id', 'wrb.quantity as quantity', 'w.reason as reason', 'sh.name as shop_name', 'u.name as user_name')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'waste', 'type_label' => 'هالك',
                    'reference_number' => 'WR-' . $r->waste_id,
                    'quantity_delta' => -round((float) $r->quantity, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name, 'notes' => $r->reason,
                ]);
            });

        DB::table('inventory_adjustment_batches as iab')
            ->join('inventory_adjustment_requests as iar', 'iar.id', '=', 'iab.inventory_adjustment_request_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'iar.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'iar.requested_by')
            ->whereIn('iab.goods_id', $goodsIds)
            ->select(
                'iar.executed_at as date', 'iar.id as adj_id', 'iab.quantity_delta as quantity_delta',
                'iar.reason as reason', 'iar.inventory_count_session_id as count_session_id',
                'sh.name as shop_name', 'u.name as user_name'
            )
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date,
                    'type' => $r->count_session_id ? 'count_adjustment' : 'adjustment',
                    'type_label' => $r->count_session_id ? 'تسوية جرد' : 'تسوية يدوية',
                    'reference_number' => 'ADJ-' . $r->adj_id,
                    'quantity_delta' => round((float) $r->quantity_delta, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name, 'notes' => $r->reason,
                ]);
            });

        $sorted = $events->sortBy('date')->values();
        $running = 0.0;
        $timeline = $sorted->map(function ($e) use (&$running) {
            $running = round($running + $e['quantity_delta'], 3);
            $e['running_remaining'] = $running;

            return $e;
        });

        // The authoritative remaining quantity always comes from Goods.current_quantity
        // (summary.remaining_quantity). It can differ from the last timeline
        // running_remaining when stock was consumed before batch-level link tables
        // existed (untracked history) — shown honestly rather than force-reconciled,
        // same principle as the Stock Movement Report's opening balance.
        $lastRunning = $timeline->last()['running_remaining'] ?? round((float) $supplyItem->quantity, 3);
        $untrackedGap = round($summary['remaining_quantity'] - $lastRunning, 3);

        return response()->json(['message' => 'ok', 'data' => [
            'batch' => $summary,
            'locations' => $locations,
            'timeline' => $timeline,
            'current_remaining_quantity' => $summary['remaining_quantity'],
            'untracked_gap' => $untrackedGap,
        ]]);
    }

    /**
     * Cross-batch Movement History — the same 4 event sources show()'s
     * per-batch timeline already reads (purchase/sale/transfer/waste/
     * adjustment link tables), unioned across every batch matching the
     * current filters instead of just one. Same filters as index()
     * (product/supplier/shop/date range/search/status), plus a movement
     * 'type' filter. No inventory logic duplicated — every source query is
     * the exact same shape already proven in show().
     */
    private function movementRows(array $filters, int $limit = 500): \Illuminate\Support\Collection
    {
        $supplyItemIds = $this->baseQuery($filters)->limit(2000)->pluck('supply_items.id');
        if ($supplyItemIds->isEmpty()) {
            return collect();
        }

        $events = collect();

        DB::table('supply_items as si')
            ->join('supplies as s', 's.id', '=', 'si.supply_id')
            ->whereIn('si.id', $supplyItemIds)
            ->select('si.id as supply_item_id', 's.date as date', 's.id as supply_id', 'si.quantity as quantity')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'purchase', 'type_label' => 'شراء',
                    'batch_id' => $r->supply_item_id, 'batch_number' => 'BATCH-' . $r->supply_item_id,
                    'reference_number' => 'SUP-' . $r->supply_id,
                    'quantity_delta' => round((float) $r->quantity, 3),
                    'shop_name' => null, 'user' => null,
                ]);
            });

        DB::table('invoice_items as ii')
            ->join('goods as g', 'g.id', '=', 'ii.goods_id')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'inv.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'inv.seller_id')
            ->where('inv.status', 'approved')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->select('inv.date as date', 'inv.id as invoice_id', 'ii.quantity as quantity', 'g.supply_item_id as batch_id', 'sh.name as shop_name', 'u.name as user_name')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'sale', 'type_label' => 'بيع',
                    'batch_id' => $r->batch_id, 'batch_number' => 'BATCH-' . $r->batch_id,
                    'reference_number' => 'INV-' . $r->invoice_id,
                    'quantity_delta' => -round((float) $r->quantity, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                ]);
            });

        DB::table('transfer_request_item_batches as trib')
            ->join('goods as g', 'g.id', '=', 'trib.goods_id')
            ->join('transfer_request_items as tri', 'tri.id', '=', 'trib.transfer_request_item_id')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->leftJoin('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->leftJoin('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'tr.requested_by')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->select(
                'g.supply_item_id as batch_id', 'tr.shipped_at', 'tr.received_at', 'tr.request_number',
                'trib.quantity_shipped', 'trib.quantity_received',
                'src.name as source_shop_name', 'dst.name as destination_shop_name', 'u.name as user_name'
            )
            ->get()->each(function ($r) use ($events) {
                if ($r->shipped_at) {
                    $events->push([
                        'date' => (string) $r->shipped_at, 'type' => 'transfer_out', 'type_label' => 'نقل صادر',
                        'batch_id' => $r->batch_id, 'batch_number' => 'BATCH-' . $r->batch_id,
                        'reference_number' => $r->request_number,
                        'quantity_delta' => -round((float) $r->quantity_shipped, 3),
                        'shop_name' => $r->source_shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                    ]);
                }
                if ($r->received_at && (float) $r->quantity_received > 0) {
                    $events->push([
                        'date' => (string) $r->received_at, 'type' => 'transfer_in', 'type_label' => 'نقل وارد',
                        'batch_id' => $r->batch_id, 'batch_number' => 'BATCH-' . $r->batch_id,
                        'reference_number' => $r->request_number,
                        'quantity_delta' => round((float) $r->quantity_received, 3),
                        'shop_name' => $r->destination_shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                    ]);
                }
            });

        DB::table('waste_record_batches as wrb')
            ->join('goods as g', 'g.id', '=', 'wrb.goods_id')
            ->join('waste_records as w', 'w.id', '=', 'wrb.waste_record_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'w.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->select('g.supply_item_id as batch_id', 'w.date as date', 'w.id as waste_id', 'wrb.quantity as quantity', 'sh.name as shop_name', 'u.name as user_name')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'waste', 'type_label' => 'هالك',
                    'batch_id' => $r->batch_id, 'batch_number' => 'BATCH-' . $r->batch_id,
                    'reference_number' => 'WR-' . $r->waste_id,
                    'quantity_delta' => -round((float) $r->quantity, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                ]);
            });

        DB::table('inventory_adjustment_batches as iab')
            ->join('goods as g', 'g.id', '=', 'iab.goods_id')
            ->join('inventory_adjustment_requests as iar', 'iar.id', '=', 'iab.inventory_adjustment_request_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'iar.shop_id')
            ->leftJoin('users as u', 'u.id', '=', 'iar.requested_by')
            ->whereIn('g.supply_item_id', $supplyItemIds)
            ->select('g.supply_item_id as batch_id', 'iar.executed_at as date', 'iar.id as adj_id', 'iab.quantity_delta as quantity_delta', 'sh.name as shop_name', 'u.name as user_name')
            ->get()->each(function ($r) use ($events) {
                $events->push([
                    'date' => (string) $r->date, 'type' => 'adjustment', 'type_label' => 'تسوية مخزون',
                    'batch_id' => $r->batch_id, 'batch_number' => 'BATCH-' . $r->batch_id,
                    'reference_number' => 'ADJ-' . $r->adj_id,
                    'quantity_delta' => round((float) $r->quantity_delta, 3),
                    'shop_name' => $r->shop_name ?? 'المستودع الرئيسي', 'user' => $r->user_name,
                ]);
            });

        if (! empty($filters['from'])) {
            $events = $events->filter(fn ($e) => $e['date'] >= $filters['from']);
        }
        if (! empty($filters['to'])) {
            $events = $events->filter(fn ($e) => substr($e['date'], 0, 10) <= $filters['to']);
        }

        return $events->sortByDesc('date')->values()->take($limit);
    }

    // ── GET /branch-operations/reports/batches/movements ─────────────────────
    public function movements(Request $request)
    {
        $filters = $this->filters($request);
        $rows = $this->movementRows($filters, (int) $request->get('limit', 200));

        return response()->json(['message' => 'ok', 'data' => [
            'rows' => $rows,
            'total_in' => round($rows->where('quantity_delta', '>', 0)->sum('quantity_delta'), 3),
            'total_out' => round(abs($rows->where('quantity_delta', '<', 0)->sum('quantity_delta')), 3),
        ]]);
    }

    // ── GET /branch-operations/reports/batches/movements/export?format=pdf|excel ──
    public function movementsExport(Request $request)
    {
        $filters = $this->filters($request);
        $rows = $this->movementRows($filters, 2000);

        $columns = ['التاريخ', 'النوع', 'رقم الدفعة', 'المرجع', 'الفرع', 'الكمية', 'المستخدم'];
        $tableRows = $rows->map(fn ($r) => [
            $r['date'], $r['type_label'], $r['batch_number'], $r['reference_number'],
            $r['shop_name'] ?? '—', $r['quantity_delta'], $r['user'] ?? '—',
        ])->all();

        $filterLabels = array_filter(['من' => $filters['from'], 'إلى' => $filters['to']], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('سجل حركة الدفعات', $columns, $tableRows, $filterLabels)
            : $this->exportService->pdf('سجل حركة الدفعات', $columns, $tableRows, $filterLabels);
    }
}
