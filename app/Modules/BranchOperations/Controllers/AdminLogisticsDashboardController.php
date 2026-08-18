<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Modules\BranchOperations\Models\InventoryCountItem;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Part 11 + Phase 4.2 — cross-branch logistics overview for admins.
 * Inventory Value Per Branch is deliberately NOT duplicated here — the
 * frontend reads it straight from /admin/stock-intelligence/overview's
 * by_location array, which already computes exactly that.
 *
 * Phase 4.2 extends the same endpoint (no new route) with global open/
 * pending-shipment/pending-receiving/today counts, a per-branch performance
 * table (heat-map highlights are derived from this array client-side, not
 * duplicated as separate endpoints), and trend series for the admin charts.
 */
class AdminLogisticsDashboardController extends Controller
{
    /**
     * "Delayed" is a heuristic, not a stored SLA (no due-date field exists on
     * transfer_requests): submitted >48h without a decision, or shipped >72h
     * without being received. Both thresholds are conservative defaults.
     */
    private const SUBMITTED_DELAY_HOURS = 48;
    private const SHIPPED_DELAY_HOURS = 72;

    public function __construct(private WarehouseResolver $warehouse) {}

    public function overview(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $today = now()->toDateString();

        $byStatus = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');

        $waitingApproval = TransferRequest::where('status', TransferRequest::STATUS_SUBMITTED)->count();

        $delayedSubmittedQuery = TransferRequest::where('status', TransferRequest::STATUS_SUBMITTED)
            ->where('created_at', '<', now()->subHours(self::SUBMITTED_DELAY_HOURS));
        $delayedShippedQuery = TransferRequest::where('status', TransferRequest::STATUS_SHIPPED)
            ->where('shipped_at', '<', now()->subHours(self::SHIPPED_DELAY_HOURS));

        $delayedSubmitted = (clone $delayedSubmittedQuery)->with(['sourceShop:id,name', 'destinationShop:id,name'])->get();
        $delayedShipped = (clone $delayedShippedQuery)->with(['sourceShop:id,name', 'destinationShop:id,name'])->get();

        $mostSending = TransferRequest::whereBetween('transfer_requests.created_at', [$from, $to . ' 23:59:59'])
            ->join('shops', 'shops.id', '=', 'transfer_requests.source_shop_id')
            ->selectRaw('shops.id, shops.name, COUNT(*) as cnt')
            ->groupBy('shops.id', 'shops.name')->orderByDesc('cnt')->first();

        $mostReceiving = TransferRequest::whereBetween('transfer_requests.created_at', [$from, $to . ' 23:59:59'])
            ->join('shops', 'shops.id', '=', 'transfer_requests.destination_shop_id')
            ->selectRaw('shops.id, shops.name, COUNT(*) as cnt')
            ->groupBy('shops.id', 'shops.name')->orderByDesc('cnt')->first();

        $mostTransferredProduct = DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('p.id, p.name, SUM(tri.requested_quantity) as total_qty')
            ->groupBy('p.id', 'p.name')->orderByDesc('total_qty')->first();

        $wasteByReason = WasteRecord::whereBetween('date', [$from, $to])
            ->selectRaw('reason, COUNT(*) as cnt, COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(estimated_value),0) as value')
            ->groupBy('reason')->get();

        $pendingCounts = InventoryCountSession::whereIn('status', [
            InventoryCountSession::STATUS_COUNTING, InventoryCountSession::STATUS_REVIEW, InventoryCountSession::STATUS_APPROVED,
        ])->count();

        // Phase 4.2 — global snapshot KPIs (not period-bound: these describe current state).
        $transfersTodayCount = TransferRequest::whereDate('created_at', $today)->count();
        $openTransfersCount = TransferRequest::whereNotIn('status', [TransferRequest::STATUS_CLOSED, TransferRequest::STATUS_REJECTED])->count();
        $pendingShipmentCount = TransferRequest::whereIn('status', [TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING])->count();
        $pendingReceivingCount = TransferRequest::where('status', TransferRequest::STATUS_SHIPPED)->count();

        $branchPerformance = $this->branchPerformance($from, $to, $delayedSubmittedQuery, $delayedShippedQuery);
        $warehouseSummary = $this->warehouseSummary($from, $to, $today, $delayedSubmittedQuery, $delayedShippedQuery);

        // Trends for the admin charts (period-bound).
        $trendTransfersPerDay = $this->dailySeries(TransferRequest::query(), 'created_at', $from, $to, 'count');
        $trendWaste = $this->dailySeries(WasteRecord::query(), 'date', $from, $to, 'value', 'estimated_value');
        $trendAdjustments = $this->dailySeries(InventoryAdjustmentRequest::query(), 'created_at', $from, $to, 'count');

        $trendInventoryAccuracy = InventoryCountSession::whereIn('status', [InventoryCountSession::STATUS_APPROVED, InventoryCountSession::STATUS_COMPLETED])
            ->with('shop:id,name')
            ->withCount(['items as total_counted' => fn ($q) => $q->whereNotNull('physical_quantity')])
            ->withCount(['items as matched' => fn ($q) => $q->whereNotNull('physical_quantity')->where('difference', 0)])
            ->orderByDesc('id')->limit(15)->get()->reverse()->values()
            ->map(fn ($s) => [
                'session_id' => $s->id,
                'shop_name' => $s->shop->name ?? null,
                'date' => $s->created_at->toDateString(),
                'accuracy_percent' => $s->total_counted > 0 ? round($s->matched / $s->total_counted * 100, 1) : null,
            ]);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'total_transfers' => (int) $byStatus->sum(),
                'by_status' => $byStatus,
                'waiting_approval_count' => $waitingApproval,
                'transfers_today_count' => $transfersTodayCount,
                'open_transfers_count' => $openTransfersCount,
                'pending_shipment_count' => $pendingShipmentCount,
                'pending_receiving_count' => $pendingReceivingCount,
                'delayed' => [
                    'submitted' => $delayedSubmitted,
                    'shipped' => $delayedShipped,
                    'total' => $delayedSubmitted->count() + $delayedShipped->count(),
                ],
                'most_sending_branch' => $mostSending,
                'most_receiving_branch' => $mostReceiving,
                'most_transferred_product' => $mostTransferredProduct ? [
                    'id' => $mostTransferredProduct->id, 'name' => $mostTransferredProduct->name,
                    'total_qty' => round((float) $mostTransferredProduct->total_qty, 3),
                ] : null,
                'waste_summary' => [
                    'total_value' => round((float) $wasteByReason->sum('value'), 2),
                    'total_qty' => round((float) $wasteByReason->sum('qty'), 3),
                    'by_reason' => $wasteByReason,
                ],
                'pending_inventory_counts' => $pendingCounts,
                'branch_performance' => $branchPerformance,
                'warehouse' => $warehouseSummary,
                'charts' => [
                    'transfers_per_day' => $trendTransfersPerDay,
                    'waste_trend' => $trendWaste,
                    'adjustment_trend' => $trendAdjustments,
                    'inventory_count_accuracy' => $trendInventoryAccuracy,
                ],
            ],
        ]);
    }

    /**
     * Per-branch performance row — heat-map highlights ("most requested",
     * "highest waste", "lowest accuracy", "highest delay") are derived from
     * this SAME array on the frontend, never as separate backend queries.
     */
    private function branchPerformance(string $from, string $to, $delayedSubmittedQuery, $delayedShippedQuery): array
    {
        $shops = Shop::select('id', 'name')->get();

        $incoming = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('destination_shop_id as shop_id, COUNT(*) as cnt')->groupBy('destination_shop_id')->pluck('cnt', 'shop_id');
        $outgoing = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('source_shop_id as shop_id, COUNT(*) as cnt')->groupBy('source_shop_id')->pluck('cnt', 'shop_id');
        $pendingRequests = TransferRequest::where('status', TransferRequest::STATUS_SUBMITTED)
            ->selectRaw('destination_shop_id as shop_id, COUNT(*) as cnt')->groupBy('destination_shop_id')->pluck('cnt', 'shop_id');
        $wasteCost = WasteRecord::whereBetween('date', [$from, $to])
            ->selectRaw('shop_id, COALESCE(SUM(estimated_value),0) as value')->groupBy('shop_id')->pluck('value', 'shop_id');

        $delayedBySourceShop = (clone $delayedShippedQuery)->selectRaw('source_shop_id as shop_id, COUNT(*) as cnt')->groupBy('source_shop_id')->pluck('cnt', 'shop_id');
        $delayedByDestShop = (clone $delayedSubmittedQuery)->selectRaw('destination_shop_id as shop_id, COUNT(*) as cnt')->groupBy('destination_shop_id')->pluck('cnt', 'shop_id');

        $accuracyRows = InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->where('s.created_at', '>=', now()->subDays(30))
            ->whereNotNull('inventory_count_items.physical_quantity')
            ->selectRaw('s.shop_id, COUNT(*) as total, SUM(CASE WHEN inventory_count_items.difference = 0 THEN 1 ELSE 0 END) as matched')
            ->groupBy('s.shop_id')->get()->keyBy('shop_id');

        return $shops->map(function ($shop) use ($incoming, $outgoing, $pendingRequests, $wasteCost, $delayedBySourceShop, $delayedByDestShop, $accuracyRows) {
            $acc = $accuracyRows->get($shop->id);
            return [
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
                'incoming' => (int) ($incoming[$shop->id] ?? 0),
                'outgoing' => (int) ($outgoing[$shop->id] ?? 0),
                'waste_cost' => round((float) ($wasteCost[$shop->id] ?? 0), 2),
                'inventory_accuracy' => ($acc && $acc->total > 0) ? round($acc->matched / $acc->total * 100, 1) : null,
                'delayed_transfers' => (int) ($delayedBySourceShop[$shop->id] ?? 0) + (int) ($delayedByDestShop[$shop->id] ?? 0),
                'pending_requests' => (int) ($pendingRequests[$shop->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Phase 5.5 — warehouse-specific admin KPIs. The warehouse is a real Shop
     * row (Phase 5.0/5.2) so it already appears in branchPerformance() above
     * like any other branch; this block adds the KPIs the admin specifically
     * needs as the warehouse's manager (Part 5.1), reusing the SAME queries/
     * conventions as the rest of this controller — no new data source.
     * Warehouse Inventory Value is deliberately NOT duplicated here — it's
     * already returned as `warehouse_value` by /admin/stock-intelligence/overview,
     * the same convention this file already follows for per-branch values.
     */
    private function warehouseSummary(string $from, string $to, string $today, $delayedSubmittedQuery, $delayedShippedQuery): array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        if (! $warehouseId) {
            return [];
        }

        $fromWarehouse = fn ($q) => $q->where('source_shop_id', $warehouseId);

        $pendingRequests = TransferRequest::where(fn ($q) => $fromWarehouse($q))->where('status', TransferRequest::STATUS_SUBMITTED)->count();
        $openRequests = TransferRequest::where(fn ($q) => $fromWarehouse($q))
            ->whereNotIn('status', [TransferRequest::STATUS_CLOSED, TransferRequest::STATUS_REJECTED])->count();
        $shipmentsToday = TransferRequest::where(fn ($q) => $fromWarehouse($q))->whereDate('shipped_at', $today)->count();
        $delays = (clone $delayedSubmittedQuery)->where('source_shop_id', $warehouseId)->count()
            + (clone $delayedShippedQuery)->where('source_shop_id', $warehouseId)->count();

        $topRequestedProducts = DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->where('tr.source_shop_id', $warehouseId)
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('p.id, p.name, SUM(tri.requested_quantity) as total_qty, COUNT(*) as request_count')
            ->groupBy('p.id', 'p.name')->orderByDesc('total_qty')->limit(5)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'total_qty' => round((float) $r->total_qty, 3), 'request_count' => (int) $r->request_count]);

        $topRequestingBranches = TransferRequest::where(fn ($q) => $fromWarehouse($q))
            ->join('shops', 'shops.id', '=', 'transfer_requests.destination_shop_id')
            ->whereBetween('transfer_requests.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('shops.id, shops.name, COUNT(*) as request_count')
            ->groupBy('shops.id', 'shops.name')->orderByDesc('request_count')->limit(5)->get();

        return [
            'shop_id' => $warehouseId,
            'pending_requests' => $pendingRequests,
            'open_requests' => $openRequests,
            'shipments_today' => $shipmentsToday,
            'delays' => $delays,
            'top_requested_products' => $topRequestedProducts,
            'top_requesting_branches' => $topRequestingBranches,
        ];
    }

    /** @return array<int, array<string, mixed>> Daily series over [$from,$to] inclusive, zero-filled. */
    private function dailySeries($query, string $dateColumn, string $from, string $to, string $key, ?string $sumColumn = null): array
    {
        if ($sumColumn) {
            $rows = $query->whereBetween($dateColumn, [$from, $to])
                ->selectRaw("{$dateColumn} as d, COALESCE(SUM({$sumColumn}),0) as v")
                ->groupBy('d')->orderBy('d')->pluck('v', 'd');
        } else {
            $rows = $query->whereBetween($dateColumn, [$from, $to . ' 23:59:59'])
                ->selectRaw("DATE({$dateColumn}) as d, COUNT(*) as v")
                ->groupBy('d')->orderBy('d')->pluck('v', 'd');
        }

        $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($to)->startOfDay();
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dateKey = $d->toDateString();
            $out[] = ['date' => $dateKey, $key => isset($rows[$dateKey]) ? round((float) $rows[$dateKey], 3) : 0];
        }
        return $out;
    }

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }
}
