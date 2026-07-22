<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.10 — Inventory Adjustment Reports. "By Reason" groups by origin
 * (stock-count-driven vs. manual correction) using the existing
 * inventory_count_session_id nullability — InventoryAdjustmentRequest.reason
 * is free text (often auto-generated per session, e.g. "جرد مخزون #4 —
 * ..."), so grouping on it verbatim would produce one group per row, not a
 * meaningful category. This reuses a real existing signal instead of
 * inventing a reason taxonomy the system doesn't actually track.
 */
class InventoryAdjustmentReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }

    // ── GET /branch-operations/reports/adjustments/summary ───────────────────
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $base = InventoryAdjustmentRequest::whereBetween('created_at', [$from, $to . ' 23:59:59']);

        $pending = (clone $base)->where('status', InventoryAdjustmentRequest::STATUS_PENDING)->count();
        $approved = (clone $base)->where('status', InventoryAdjustmentRequest::STATUS_APPROVED)->count();
        $rejected = (clone $base)->where('status', InventoryAdjustmentRequest::STATUS_REJECTED)->count();
        $executed = (clone $base)->where('status', InventoryAdjustmentRequest::STATUS_EXECUTED)->count();

        $executedBase = (clone $base)->where('status', InventoryAdjustmentRequest::STATUS_EXECUTED);
        $positiveSum = (clone $executedBase)->where('difference', '>', 0)->sum('difference');
        $negativeSum = (clone $executedBase)->where('difference', '<', 0)->sum('difference');

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'pending_count' => $pending,
                'approved_count' => $approved,
                'rejected_count' => $rejected,
                'executed_count' => $executed,
                'total_positive_qty' => round((float) $positiveSum, 3),
                'total_negative_qty' => round((float) $negativeSum, 3),
                'net_qty' => round((float) ($positiveSum + $negativeSum), 3),
            ],
        ]);
    }

    private function baseRow($r): array
    {
        return [
            'id' => $r->id, 'shop_name' => $r->shop->name ?? '', 'product_name' => $r->product->name ?? '', 'sku' => $r->product->sku ?? '—',
            'before_quantity' => round((float) $r->before_quantity, 3), 'after_quantity' => round((float) $r->after_quantity, 3),
            'difference' => round((float) $r->difference, 3), 'status' => $r->status,
            'requested_by' => $r->requestedByUser->name ?? '', 'reason' => $r->reason,
            'origin' => $r->inventory_count_session_id ? 'جلسة جرد #' . $r->inventory_count_session_id : 'تسوية يدوية',
            'created_at' => $r->created_at->toDateTimeString(),
        ];
    }

    private function listRows(string $from, string $to, ?string $sign = null): array
    {
        $q = InventoryAdjustmentRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->with(['shop:id,name', 'product:id,name,sku', 'requestedByUser:id,name']);

        if ($sign === 'positive') {
            $q->where('difference', '>', 0);
        } elseif ($sign === 'negative') {
            $q->where('difference', '<', 0);
        }

        return $q->orderByDesc('created_at')->get()->map(fn ($r) => $this->baseRow($r))->all();
    }

    private function byBranch(string $from, string $to): array
    {
        return InventoryAdjustmentRequest::join('shops as s', 's.id', '=', 'inventory_adjustment_requests.shop_id')
            ->whereBetween('inventory_adjustment_requests.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('s.id as shop_id, s.name as shop_name, COUNT(*) as request_count,
                SUM(CASE WHEN inventory_adjustment_requests.difference > 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as positive_qty,
                SUM(CASE WHEN inventory_adjustment_requests.difference < 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as negative_qty')
            ->groupBy('s.id', 's.name')->orderByDesc('request_count')->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'request_count' => (int) $r->request_count, 'positive_qty' => round((float) $r->positive_qty, 3), 'negative_qty' => round((float) $r->negative_qty, 3)])
            ->all();
    }

    private function byProduct(string $from, string $to): array
    {
        return InventoryAdjustmentRequest::join('products as p', 'p.id', '=', 'inventory_adjustment_requests.product_id')
            ->whereBetween('inventory_adjustment_requests.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, COUNT(*) as request_count,
                SUM(CASE WHEN inventory_adjustment_requests.difference > 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as positive_qty,
                SUM(CASE WHEN inventory_adjustment_requests.difference < 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as negative_qty')
            ->groupBy('p.id', 'p.name', 'p.sku')->orderByDesc('request_count')->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'request_count' => (int) $r->request_count, 'positive_qty' => round((float) $r->positive_qty, 3), 'negative_qty' => round((float) $r->negative_qty, 3)])
            ->all();
    }

    private function byEmployee(string $from, string $to): array
    {
        return InventoryAdjustmentRequest::join('users as u', 'u.id', '=', 'inventory_adjustment_requests.requested_by')
            ->whereBetween('inventory_adjustment_requests.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('u.id as user_id, u.name as user_name, COUNT(*) as request_count,
                SUM(CASE WHEN inventory_adjustment_requests.difference > 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as positive_qty,
                SUM(CASE WHEN inventory_adjustment_requests.difference < 0 THEN inventory_adjustment_requests.difference ELSE 0 END) as negative_qty')
            ->groupBy('u.id', 'u.name')->orderByDesc('request_count')->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'user_name' => $r->user_name, 'request_count' => (int) $r->request_count, 'positive_qty' => round((float) $r->positive_qty, 3), 'negative_qty' => round((float) $r->negative_qty, 3)])
            ->all();
    }

    private function byReason(string $from, string $to): array
    {
        return InventoryAdjustmentRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw("CASE WHEN inventory_count_session_id IS NOT NULL THEN 'stock_count' ELSE 'manual' END as origin,
                COUNT(*) as request_count,
                SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END) as positive_qty,
                SUM(CASE WHEN difference < 0 THEN difference ELSE 0 END) as negative_qty")
            ->groupBy('origin')->get()
            ->map(fn ($r) => ['origin' => $r->origin, 'origin_label' => $r->origin === 'stock_count' ? 'ناتج عن جلسة جرد' : 'تسوية يدوية', 'request_count' => (int) $r->request_count, 'positive_qty' => round((float) $r->positive_qty, 3), 'negative_qty' => round((float) $r->negative_qty, 3)])
            ->all();
    }

    private function monthlyTrend(string $from, string $to): array
    {
        return InventoryAdjustmentRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as request_count,
                SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END) as positive_qty,
                SUM(CASE WHEN difference < 0 THEN difference ELSE 0 END) as negative_qty")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'request_count' => (int) $r->request_count, 'positive_qty' => round((float) $r->positive_qty, 3), 'negative_qty' => round((float) $r->negative_qty, 3)])
            ->all();
    }

    private function build(string $type, string $from, string $to): ?array
    {
        return match ($type) {
            'positive' => $this->listRows($from, $to, 'positive'),
            'negative' => $this->listRows($from, $to, 'negative'),
            'by-branch' => $this->byBranch($from, $to),
            'by-product' => $this->byProduct($from, $to),
            'by-employee' => $this->byEmployee($from, $to),
            'by-reason' => $this->byReason($from, $to),
            'monthly-trend' => $this->monthlyTrend($from, $to),
            default => null,
        };
    }

    // ── GET /branch-operations/reports/adjustments/{type} ─────────────────────
    public function data(Request $request, string $type)
    {
        [$from, $to] = $this->parseDates($request);
        $rows = $this->build($type, $from, $to);

        if ($rows === null) {
            return response()->json(['message' => 'نوع تقرير غير معروف', 'data' => []], 404);
        }

        return response()->json(['message' => 'ok', 'data' => $rows, 'period' => ['from' => $from, 'to' => $to]]);
    }

    private const COLUMNS = [
        'positive' => ['#', 'الفرع', 'المنتج', 'الكود', 'قبل', 'بعد', 'الفرق', 'الحالة', 'الأصل', 'طلب بواسطة', 'التاريخ'],
        'negative' => ['#', 'الفرع', 'المنتج', 'الكود', 'قبل', 'بعد', 'الفرق', 'الحالة', 'الأصل', 'طلب بواسطة', 'التاريخ'],
        'by-branch' => ['الفرع', 'عدد الطلبات', 'إجمالي الزيادة', 'إجمالي النقص'],
        'by-product' => ['المنتج', 'الكود', 'عدد الطلبات', 'إجمالي الزيادة', 'إجمالي النقص'],
        'by-employee' => ['الموظف', 'عدد الطلبات', 'إجمالي الزيادة', 'إجمالي النقص'],
        'by-reason' => ['الأصل', 'عدد الطلبات', 'إجمالي الزيادة', 'إجمالي النقص'],
        'monthly-trend' => ['الشهر', 'عدد الطلبات', 'إجمالي الزيادة', 'إجمالي النقص'],
    ];

    private const TITLES = [
        'positive' => 'التسويات الموجبة', 'negative' => 'التسويات السالبة', 'by-branch' => 'التسويات حسب الفرع',
        'by-product' => 'التسويات حسب المنتج', 'by-employee' => 'التسويات حسب الموظف', 'by-reason' => 'التسويات حسب الأصل',
        'monthly-trend' => 'الاتجاه الشهري للتسويات',
    ];

    private function toRow(string $type, array $r): array
    {
        return match ($type) {
            'positive', 'negative' => [$r['id'], $r['shop_name'], $r['product_name'], $r['sku'], $r['before_quantity'], $r['after_quantity'], $r['difference'], $r['status'], $r['origin'], $r['requested_by'], $r['created_at']],
            'by-branch' => [$r['shop_name'], $r['request_count'], $r['positive_qty'], $r['negative_qty']],
            'by-product' => [$r['product_name'], $r['sku'], $r['request_count'], $r['positive_qty'], $r['negative_qty']],
            'by-employee' => [$r['user_name'], $r['request_count'], $r['positive_qty'], $r['negative_qty']],
            'by-reason' => [$r['origin_label'], $r['request_count'], $r['positive_qty'], $r['negative_qty']],
            'monthly-trend' => [$r['month'], $r['request_count'], $r['positive_qty'], $r['negative_qty']],
            default => [],
        };
    }

    // ── GET /branch-operations/reports/adjustments/{type}/export?format=pdf|excel ──
    public function export(Request $request, string $type)
    {
        [$from, $to] = $this->parseDates($request);
        $rows = $this->build($type, $from, $to);

        if ($rows === null || ! isset(self::COLUMNS[$type])) {
            abort(404, 'نوع تقرير غير معروف');
        }

        $columns = self::COLUMNS[$type];
        $tableRows = array_map(fn ($r) => $this->toRow($type, $r), $rows);
        $title = self::TITLES[$type];
        $filters = array_filter(['من' => $from, 'إلى' => $to]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel($title, $columns, $tableRows, $filters)
            : $this->exportService->pdf($title, $columns, $tableRows, $filters);
    }
}
