<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Modules\BranchOperations\Models\InventoryCountItem;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Modules\BranchOperations\Services\InventoryCountService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

class InventoryCountController extends Controller
{
    public function __construct(private InventoryCountService $service, private ReportExportService $exportService) {}

    private function baseQuery(Request $request)
    {
        $q = InventoryCountSession::with(['shop:id,name', 'createdByUser:id,name', 'employees:id,name'])->withCount('items');

        $user = $request->user();
        if (in_array($user->role, ['manager', 'sales'], true) && $user->shop_id) {
            $q->where('shop_id', $user->shop_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }
        if ($request->filled('shop_id')) {
            $q->where('shop_id', (int) $request->get('shop_id'));
        }
        if ($request->filled('search')) {
            $term = $request->get('search');
            $q->where(function ($sq) use ($term) {
                $sq->where('id', 'like', "%{$term}%")
                   ->orWhereHas('items.product', function ($pq) use ($term) {
                       $pq->where('name', 'like', "%{$term}%")
                          ->orWhere('sku', 'like', "%{$term}%")
                          ->orWhere('barcode', 'like', "%{$term}%");
                   });
            });
        }

        return $q;
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 25), 100);
        $result = $this->baseQuery($request)->orderByDesc('id')->paginate($perPage);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    public function show(Request $request, int $id)
    {
        $session = $this->baseQuery($request)
            ->with(['items.product', 'items.countedByUser:id,name', 'approvedByUser:id,name'])
            ->findOrFail($id);

        $sessionArray = $session->toArray();
        $sessionArray['items'] = $this->attachAdjustmentInfo($session);
        $sessionArray['summary'] = $this->summaryFor($session);

        return response()->json(['message' => 'ok', 'data' => $sessionArray]);
    }

    /**
     * Per item, attaches the resulting InventoryAdjustmentRequest's
     * before/after stock quantity, its own difference, who applied it and
     * when, and whether it was actually executed — reuses the existing
     * request/approve/execute records InventoryCountService::adjustInventory()
     * already creates (one row per non-zero-difference item, linked via
     * inventory_count_session_id), so nothing new is stored here. Also
     * attaches each item's own difference_value (abs(difference) × the
     * product's current purchase_cost — same convention as summaryFor()).
     * Adjustment fields are populated only once adjustInventory() has run
     * (session status 'completed'); before that they're null/false,
     * correctly reflecting that no stock adjustment has happened yet.
     */
    private function attachAdjustmentInfo(InventoryCountSession $session): array
    {
        $adjustments = InventoryAdjustmentRequest::where('inventory_count_session_id', $session->id)
            ->with('requestedByUser:id,name')
            ->get()
            ->keyBy('product_id');

        return $session->items->map(function (InventoryCountItem $item) use ($adjustments) {
            $arr = $item->toArray();
            $adj = $adjustments->get($item->product_id);

            $cost = (float) ($item->product?->purchase_cost ?? 0);
            $arr['difference_value'] = round(abs((float) ($item->difference ?? 0)) * $cost, 2);

            $arr['stock_before_adjustment'] = $adj ? (float) $adj->before_quantity : null;
            $arr['stock_after_adjustment'] = $adj ? (float) $adj->after_quantity : null;
            $arr['adjustment_quantity'] = $adj ? (float) $adj->difference : null;
            $arr['adjustment_applied'] = $adj ? $adj->status === InventoryAdjustmentRequest::STATUS_EXECUTED : false;
            $arr['adjustment_applied_by'] = $adj?->requestedByUser?->name;
            $arr['adjustment_date'] = $adj?->executed_at?->toIso8601String();

            return $arr;
        })->all();
    }

    /**
     * Matching/shortage/excess/not-counted counts + monetary value of each —
     * a read-only derived view over the session's own items, computed fresh
     * on every request (nothing new is stored). Value uses each product's
     * current purchase_cost — the same "value what's physically different,
     * at today's cost" convention already used for current-inventory
     * valuation elsewhere (e.g. AdminStockIntelligenceController), not a
     * historical sale figure, so the live product cost is the correct
     * source here.
     *
     * Coverage: `create()` snapshots every product that had stock in this
     * shop at count-creation time into one InventoryCountItem each — that
     * IS "total products that existed in the branch." An item with
     * physical_quantity still null was never counted at all (differs from
     * "matched" — matched means counted AND no difference). This bucket was
     * previously silently dropped from the tally entirely; now explicit.
     */
    private function summaryFor(InventoryCountSession $session): array
    {
        $matching = $shortage = $excess = $notCounted = 0;
        $shortageValue = $excessValue = 0.0;

        foreach ($session->items as $item) {
            $status = $item->differenceStatus();
            $cost = (float) ($item->product?->purchase_cost ?? 0);
            $diff = (float) ($item->difference ?? 0);

            if ($status === 'match') {
                $matching++;
            } elseif ($status === 'shortage') {
                $shortage++;
                $shortageValue += abs($diff) * $cost;
            } elseif ($status === 'excess') {
                $excess++;
                $excessValue += abs($diff) * $cost;
            } else { // 'pending' — never counted this session
                $notCounted++;
            }
        }

        $total = $session->items->count();

        return [
            'total_products' => $total,
            'counted_count' => $total - $notCounted,
            'not_counted_count' => $notCounted,
            'matching_count' => $matching,
            'shortage_count' => $shortage,
            'excess_count' => $excess,
            'total_shortage_value' => round($shortageValue, 2),
            'total_excess_value' => round($excessValue, 2),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $session = $this->service->create((int) $data['shop_id'], $data['employee_ids'] ?? [], $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'تم إنشاء جلسة الجرد', 'data' => $session], 201);
    }

    public function recordCounts(Request $request, int $id)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:inventory_count_items,id',
            'items.*.physical_quantity' => 'required|numeric|min:0',
        ]);

        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->recordCounts($session, $data['items'], $request->user());

        return response()->json(['message' => 'تم تسجيل الكميات الفعلية', 'data' => $session]);
    }

    public function submitForReview(Request $request, int $id)
    {
        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->submitForReview($session, $request->user());

        return response()->json(['message' => 'تم إرسال الجلسة للمراجعة', 'data' => $session]);
    }

    public function setItemReason(Request $request, int $id, int $itemId)
    {
        $data = $request->validate([
            'reason' => 'required|string',
            // Optional — existing clients sending only `reason` keep working unchanged.
            'reason_type' => 'nullable|string|in:' . implode(',', InventoryCountItem::REASON_TYPES),
        ]);
        $session = InventoryCountSession::findOrFail($id);
        $item = $this->service->setItemReason($session, $itemId, $data['reason'], $data['reason_type'] ?? null);

        return response()->json(['message' => 'تم تحديث السبب', 'data' => $item]);
    }

    public function approve(Request $request, int $id)
    {
        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->approve($session, $request->user());

        return response()->json(['message' => 'تمت الموافقة على جلسة الجرد', 'data' => $session]);
    }

    public function adjustInventory(Request $request, int $id)
    {
        $session = InventoryCountSession::with('items.product')->findOrFail($id);
        $session = $this->service->adjustInventory($session, $request->user());

        return response()->json(['message' => 'تم تسوية المخزون بناءً على الجرد', 'data' => $session]);
    }

    /** GET /branch-operations/counts/{id}/export?format=pdf|excel — everything shown on the session detail page. */
    public function export(Request $request, int $id)
    {
        $session = $this->baseQuery($request)->with(['items.product', 'approvedByUser:id,name'])->findOrFail($id);

        $statusLabel = fn (string $s) => ['match' => 'مطابق', 'shortage' => 'عجز', 'excess' => 'زيادة', 'pending' => 'غير معدود'][$s] ?? $s;
        $reasonTypeLabel = fn (?string $t) => $t ? ([
            'broken' => 'مكسور', 'damaged' => 'تالف', 'theft' => 'سرقة',
            'sale_not_recorded' => 'بيع غير مسجل', 'purchase_not_recorded' => 'شراء غير مسجل',
            'transfer_issue' => 'مشكلة تحويل', 'counting_mistake' => 'خطأ عد', 'other' => 'أخرى',
        ][$t] ?? $t) : '—';

        // Reuses the exact same per-item enrichment the detail page's show()
        // uses (difference_value, adjustment info) — one source of truth,
        // never recomputed differently here.
        $enrichedItems = $this->attachAdjustmentInfo($session);

        $columns = ['المنتج', 'SKU', 'الباركود', 'كمية النظام', 'الكمية الفعلية', 'الفرق', 'قيمة الفرق', 'الحالة', 'نوع السبب', 'السبب', 'تمت التسوية'];
        $rows = array_map(function ($item) use ($statusLabel, $reasonTypeLabel) {
            $diff = $item['difference'] !== null ? (float) $item['difference'] : null;
            $rowStatus = $item['physical_quantity'] === null ? 'pending' : ($diff === 0.0 ? 'match' : ($diff > 0 ? 'excess' : 'shortage'));

            return [
                $item['product']['name'] ?? '—',
                $item['product']['sku'] ?? '—',
                $item['product']['barcode'] ?? '—',
                (float) $item['system_quantity'],
                $item['physical_quantity'] !== null ? (float) $item['physical_quantity'] : '—',
                $diff ?? '—',
                (float) ($item['difference_value'] ?? 0),
                $statusLabel($rowStatus),
                $reasonTypeLabel($item['reason_type'] ?? null),
                $item['reason'] ?? '—',
                $item['adjustment_applied'] ? 'نعم' : 'لا',
            ];
        }, $enrichedItems);

        $filters = array_filter([
            'الفرع' => $session->shop?->name,
            'الحالة' => $session->status,
            'اعتمد بواسطة' => $session->approvedByUser?->name,
            'تاريخ الاعتماد' => $session->approved_at?->format('Y-m-d H:i'),
        ]);

        $title = 'تفاصيل جلسة جرد #' . $session->id;

        return $request->input('format') === 'excel'
            ? $this->exportService->excel($title, $columns, $rows, $filters, [3, 4, 5, 6])
            : $this->exportService->pdf($title, $columns, $rows, $filters);
    }
}
