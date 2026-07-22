<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InternalTransferInvoice;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Modules\BranchOperations\Models\InventoryCountItem;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\TransferRequestItem;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 (Part 10 + 4.1) — a single branch's operations at a glance. Low
 * Stock and Inventory Value are deliberately NOT duplicated here — the
 * frontend reads those straight from the existing /admin/stock-intelligence
 * endpoints (low-stock, overview.by_location) which already do this per-shop.
 *
 * Phase 4.1 extends the same endpoint (no new route) with waiting-shipment/
 * waiting-receiving/closed/rejected counts, internal-invoice count, today's
 * item-movement KPIs, an inventory-accuracy percentage, and three trend
 * series for the branch dashboard's charts.
 */
class BranchDashboardController extends Controller
{
    public function __construct(private WarehouseResolver $warehouse) {}

    /** GET /branch-operations/dashboard?shop_id= — manager defaults to their own shop; admin must pass one. */
    public function show(Request $request)
    {
        $user = $request->user();
        $shopId = $user->role === 'manager' && $user->shop_id
            ? $user->shop_id
            : (int) $request->get('shop_id', 0);

        if (! $shopId) {
            return response()->json(['message' => 'يجب تحديد الفرع', 'data' => null], 422);
        }

        $touchesShop = fn ($q) => $q->where(function ($sq) use ($shopId) {
            $sq->where('source_shop_id', $shopId)->orWhere('destination_shop_id', $shopId);
        });
        $today = now()->toDateString();

        $pendingTransfers = TransferRequest::where(fn ($q) => $touchesShop($q))
            ->where('status', TransferRequest::STATUS_SUBMITTED)->count();

        $incomingTransfers = TransferRequest::where('destination_shop_id', $shopId)
            ->whereIn('status', [TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING, TransferRequest::STATUS_SHIPPED])
            ->count();

        $outgoingTransfers = TransferRequest::where('source_shop_id', $shopId)
            ->whereIn('status', [TransferRequest::STATUS_SUBMITTED, TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING, TransferRequest::STATUS_SHIPPED])
            ->count();

        $pendingReceipts = TransferRequest::where('destination_shop_id', $shopId)
            ->where('status', TransferRequest::STATUS_SHIPPED)->count();

        // Phase 4.1 — finer-grained workflow counts alongside the existing ones above.
        $waitingShipment = TransferRequest::where('source_shop_id', $shopId)
            ->whereIn('status', [TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING])->count();
        $closedCount = TransferRequest::where(fn ($q) => $touchesShop($q))->where('status', TransferRequest::STATUS_CLOSED)->count();
        $rejectedCount = TransferRequest::where(fn ($q) => $touchesShop($q))->where('status', TransferRequest::STATUS_REJECTED)->count();
        $internalInvoicesCount = InternalTransferInvoice::whereHas('transferRequest', fn ($q) => $touchesShop($q))->count();

        $todayWaste = WasteRecord::where('shop_id', $shopId)->whereDate('date', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(estimated_value),0) as value')->first();

        $recentRequests = TransferRequest::where(fn ($q) => $touchesShop($q))
            ->with(['sourceShop:id,name', 'destinationShop:id,name'])
            ->orderByDesc('id')->limit(5)->get();

        // Phase 5.5 — this branch's requests specifically to/from the Main Warehouse.
        // Reuses the exact same touchesShop-style filters as everything above, just
        // scoped to the warehouse's real Shop id (Phase 5.0) as the source.
        $warehouseId = $this->warehouse->warehouseShopId();
        $warehouseSummary = $warehouseId ? [
            'requests' => TransferRequest::where('source_shop_id', $warehouseId)->where('destination_shop_id', $shopId)->count(),
            'pending_requests' => TransferRequest::where('source_shop_id', $warehouseId)->where('destination_shop_id', $shopId)
                ->whereIn('status', [TransferRequest::STATUS_SUBMITTED, TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING, TransferRequest::STATUS_SHIPPED])
                ->count(),
            'deliveries' => TransferRequest::where('source_shop_id', $warehouseId)->where('destination_shop_id', $shopId)
                ->whereIn('status', [TransferRequest::STATUS_RECEIVED, TransferRequest::STATUS_CLOSED])->count(),
            'history' => TransferRequest::where('source_shop_id', $warehouseId)->where('destination_shop_id', $shopId)
                ->whereIn('status', [TransferRequest::STATUS_RECEIVED, TransferRequest::STATUS_CLOSED, TransferRequest::STATUS_REJECTED])
                ->orderByDesc('id')->limit(5)->get(['id', 'request_number', 'status', 'requested_date']),
        ] : null;

        $pendingCounts = InventoryCountSession::where('shop_id', $shopId)
            ->whereIn('status', [InventoryCountSession::STATUS_COUNTING, InventoryCountSession::STATUS_REVIEW, InventoryCountSession::STATUS_APPROVED])
            ->count();

        $pendingAdjustments = InventoryAdjustmentRequest::where('shop_id', $shopId)
            ->where('status', InventoryAdjustmentRequest::STATUS_PENDING)->count();

        // Today's transfers + item movement (Phase 4.1 KPIs).
        $todayTransfersCount = TransferRequest::where(fn ($q) => $touchesShop($q))->whereDate('created_at', $today)->count();

        $itemsSentToday = (float) TransferRequestItem::whereHas(
            'transferRequest', fn ($q) => $q->where('source_shop_id', $shopId)->whereDate('shipped_at', $today)
        )->sum('requested_quantity');

        $receivedTodayItems = TransferRequestItem::whereHas(
            'transferRequest', fn ($q) => $q->where('destination_shop_id', $shopId)->whereDate('received_at', $today)
        )->selectRaw('COALESCE(SUM(received_quantity),0) as received, COALESCE(SUM(damaged_quantity),0) as damaged, COALESCE(SUM(missing_quantity),0) as missing')
            ->first();

        // Inventory accuracy over the last 30 days: share of counted items whose physical quantity matched the system quantity.
        $accuracyBase = InventoryCountItem::whereHas('session', fn ($q) => $q->where('shop_id', $shopId)->where('created_at', '>=', now()->subDays(30)))
            ->whereNotNull('physical_quantity');
        $accuracyTotal = (clone $accuracyBase)->count();
        $accuracyMatched = (clone $accuracyBase)->where('difference', 0)->count();
        $inventoryAccuracy = $accuracyTotal > 0 ? round($accuracyMatched / $accuracyTotal * 100, 1) : null;

        // Charts.
        $transfersThisWeek = $this->dailySeries(
            TransferRequest::where(fn ($q) => $touchesShop($q)), 'created_at', now()->subDays(6), $today
        );
        $wasteTrend = $this->dailyValueSeries(
            WasteRecord::where('shop_id', $shopId), 'date', 'estimated_value', now()->subDays(13), $today
        );
        $accuracyTrend = InventoryCountSession::where('shop_id', $shopId)
            ->whereIn('status', [InventoryCountSession::STATUS_APPROVED, InventoryCountSession::STATUS_COMPLETED])
            ->withCount(['items as total_counted' => fn ($q) => $q->whereNotNull('physical_quantity')])
            ->withCount(['items as matched' => fn ($q) => $q->whereNotNull('physical_quantity')->where('difference', 0)])
            ->orderByDesc('id')->limit(10)->get()->reverse()->values()
            ->map(fn ($s) => [
                'session_id' => $s->id,
                'date' => $s->created_at->toDateString(),
                'accuracy_percent' => $s->total_counted > 0 ? round($s->matched / $s->total_counted * 100, 1) : null,
            ]);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'shop_id' => $shopId,
                'pending_transfers' => $pendingTransfers,
                'incoming_transfers' => $incomingTransfers,
                'outgoing_transfers' => $outgoingTransfers,
                'pending_receipts' => $pendingReceipts,
                'waiting_shipment' => $waitingShipment,
                'closed_count' => $closedCount,
                'rejected_count' => $rejectedCount,
                'internal_invoices_count' => $internalInvoicesCount,
                'today_waste_count' => (int) $todayWaste->cnt,
                'today_waste_value' => round((float) $todayWaste->value, 2),
                'pending_inventory_counts' => $pendingCounts,
                'pending_adjustments' => $pendingAdjustments,
                'recent_requests' => $recentRequests,
                'warehouse' => $warehouseSummary,
                'today_transfers_count' => $todayTransfersCount,
                'items_sent_today' => round($itemsSentToday, 3),
                'items_received_today' => round((float) $receivedTodayItems->received, 3),
                'damaged_items_today' => round((float) $receivedTodayItems->damaged, 3),
                'missing_items_today' => round((float) $receivedTodayItems->missing, 3),
                'inventory_accuracy_percent' => $inventoryAccuracy,
                'charts' => [
                    'transfers_this_week' => $transfersThisWeek,
                    'waste_trend' => $wasteTrend,
                    'inventory_accuracy_trend' => $accuracyTrend,
                ],
            ],
        ]);
    }

    /** @return array<int, array{date:string,count:int}> */
    private function dailySeries($query, string $dateColumn, $from, $to): array
    {
        $rows = $query->selectRaw("DATE({$dateColumn}) as d, COUNT(*) as cnt")
            ->whereBetween($dateColumn, [$from, $to . ' 23:59:59'])
            ->groupBy('d')->orderBy('d')->pluck('cnt', 'd');

        return $this->fillDailyRange($rows, $from, $to, 'count');
    }

    /** @return array<int, array{date:string,value:float}> */
    private function dailyValueSeries($query, string $dateColumn, string $valueColumn, $from, $to): array
    {
        $rows = $query->selectRaw("{$dateColumn} as d, COALESCE(SUM({$valueColumn}),0) as v")
            ->whereBetween($dateColumn, [$from, $to])
            ->groupBy('d')->orderBy('d')->pluck('v', 'd');

        return $this->fillDailyRange($rows, $from, $to, 'value');
    }

    private function fillDailyRange($rows, $from, $to, string $valueKey): array
    {
        $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($to)->startOfDay();
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $out[] = ['date' => $key, $valueKey => isset($rows[$key]) ? round((float) $rows[$key], 3) : 0];
        }
        return $out;
    }
}
