<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryCountItem;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — Inventory Count Reports. "Employee Accuracy" is backed by the
 * new inventory_count_items.counted_by column (see the migration added
 * alongside this controller) — InventoryCountService::recordCounts() always
 * knew which user was recording, it just wasn't persisted per item before.
 */
class InventoryCountReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }

    // ── GET /branch-operations/reports/counts/summary ────────────────────────
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $base = InventoryCountSession::whereBetween('created_at', [$from, $to . ' 23:59:59']);

        $total = (clone $base)->count();
        $approved = (clone $base)->whereIn('status', [InventoryCountSession::STATUS_APPROVED, InventoryCountSession::STATUS_COMPLETED])->count();
        $pending = (clone $base)->whereIn('status', [InventoryCountSession::STATUS_COUNTING, InventoryCountSession::STATUS_REVIEW])->count();

        $itemStats = InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->whereBetween('s.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('inventory_count_items.physical_quantity')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN inventory_count_items.difference = 0 THEN 1 ELSE 0 END) as matched')
            ->first();

        $accuracy = $itemStats && $itemStats->total > 0 ? round($itemStats->matched / $itemStats->total * 100, 1) : null;

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'total_sessions' => $total,
                'approved_sessions' => $approved,
                'pending_sessions' => $pending,
                'total_items_counted' => (int) ($itemStats->total ?? 0),
                'overall_accuracy_percent' => $accuracy,
            ],
        ]);
    }

    private function sessions(string $from, string $to, ?string $statusFilter = null): array
    {
        $q = InventoryCountSession::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->with('shop:id,name', 'createdByUser:id,name')
            ->withCount(['items as total_counted' => fn ($q) => $q->whereNotNull('physical_quantity')])
            ->withCount(['items as matched' => fn ($q) => $q->whereNotNull('physical_quantity')->where('difference', 0)]);

        if ($statusFilter === 'approved') {
            $q->whereIn('status', [InventoryCountSession::STATUS_APPROVED, InventoryCountSession::STATUS_COMPLETED]);
        } elseif ($statusFilter === 'pending') {
            $q->whereIn('status', [InventoryCountSession::STATUS_COUNTING, InventoryCountSession::STATUS_REVIEW]);
        }

        return $q->orderByDesc('id')->get()->map(fn ($s) => [
            'session_id' => $s->id, 'shop_name' => $s->shop->name ?? '', 'created_by' => $s->createdByUser->name ?? '',
            'status' => $s->status, 'items_count' => $s->total_counted,
            'accuracy_percent' => $s->total_counted > 0 ? round($s->matched / $s->total_counted * 100, 1) : null,
            'created_at' => $s->created_at->toDateTimeString(),
        ])->all();
    }

    private function branchAccuracy(string $from, string $to): array
    {
        return InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->join('shops as sh', 'sh.id', '=', 's.shop_id')
            ->whereBetween('s.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('inventory_count_items.physical_quantity')
            ->selectRaw('sh.id as shop_id, sh.name as shop_name, COUNT(*) as total, SUM(CASE WHEN inventory_count_items.difference = 0 THEN 1 ELSE 0 END) as matched')
            ->groupBy('sh.id', 'sh.name')->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'items_counted' => (int) $r->total, 'accuracy_percent' => $r->total > 0 ? round($r->matched / $r->total * 100, 1) : null])
            ->sortBy('accuracy_percent')->values()->all();
    }

    private function employeeAccuracy(string $from, string $to): array
    {
        return InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->join('users as u', 'u.id', '=', 'inventory_count_items.counted_by')
            ->whereBetween('s.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('inventory_count_items.physical_quantity')
            ->selectRaw('u.id as user_id, u.name as user_name, COUNT(*) as total, SUM(CASE WHEN inventory_count_items.difference = 0 THEN 1 ELSE 0 END) as matched')
            ->groupBy('u.id', 'u.name')->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'user_name' => $r->user_name, 'items_counted' => (int) $r->total, 'accuracy_percent' => $r->total > 0 ? round($r->matched / $r->total * 100, 1) : null])
            ->sortBy('accuracy_percent')->values()->all();
    }

    private function productVariance(string $from, string $to): array
    {
        return InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->join('products as p', 'p.id', '=', 'inventory_count_items.product_id')
            ->whereBetween('s.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('inventory_count_items.physical_quantity')
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, COUNT(*) as times_counted,
                SUM(CASE WHEN inventory_count_items.difference <> 0 THEN 1 ELSE 0 END) as times_diff,
                SUM(ABS(inventory_count_items.difference)) as total_abs_diff')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->havingRaw('SUM(CASE WHEN inventory_count_items.difference <> 0 THEN 1 ELSE 0 END) > 0')
            ->orderByDesc('total_abs_diff')->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'times_counted' => (int) $r->times_counted, 'times_diff' => (int) $r->times_diff, 'total_abs_diff' => round((float) $r->total_abs_diff, 3)])
            ->all();
    }

    private function biggestDifferences(string $from, string $to): array
    {
        return InventoryCountItem::join('inventory_count_sessions as s', 's.id', '=', 'inventory_count_items.inventory_count_session_id')
            ->join('products as p', 'p.id', '=', 'inventory_count_items.product_id')
            ->join('shops as sh', 'sh.id', '=', 's.shop_id')
            ->whereBetween('s.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('inventory_count_items.difference')
            ->where('inventory_count_items.difference', '<>', 0)
            ->selectRaw('s.id as session_id, sh.name as shop_name, p.name as product_name, p.sku,
                inventory_count_items.system_quantity, inventory_count_items.physical_quantity, inventory_count_items.difference')
            ->orderByRaw('ABS(inventory_count_items.difference) DESC')->limit(20)->get()
            ->map(fn ($r) => [
                'session_id' => $r->session_id, 'shop_name' => $r->shop_name, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—',
                'system_quantity' => round((float) $r->system_quantity, 3), 'physical_quantity' => round((float) $r->physical_quantity, 3), 'difference' => round((float) $r->difference, 3),
            ])->all();
    }

    private function accuracyTrend(string $from, string $to): array
    {
        return InventoryCountSession::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->whereIn('status', [InventoryCountSession::STATUS_APPROVED, InventoryCountSession::STATUS_COMPLETED])
            ->with('shop:id,name')
            ->withCount(['items as total_counted' => fn ($q) => $q->whereNotNull('physical_quantity')])
            ->withCount(['items as matched' => fn ($q) => $q->whereNotNull('physical_quantity')->where('difference', 0)])
            ->orderBy('created_at')->get()
            ->map(fn ($s) => [
                'session_id' => $s->id, 'shop_name' => $s->shop->name ?? null, 'date' => $s->created_at->toDateString(),
                'accuracy_percent' => $s->total_counted > 0 ? round($s->matched / $s->total_counted * 100, 1) : null,
            ])->all();
    }

    private function build(string $type, string $from, string $to): ?array
    {
        return match ($type) {
            'sessions' => $this->sessions($from, $to),
            'branch-accuracy' => $this->branchAccuracy($from, $to),
            'employee-accuracy' => $this->employeeAccuracy($from, $to),
            'product-variance' => $this->productVariance($from, $to),
            'biggest-differences' => $this->biggestDifferences($from, $to),
            'accuracy-trend' => $this->accuracyTrend($from, $to),
            'approved' => $this->sessions($from, $to, 'approved'),
            'pending' => $this->sessions($from, $to, 'pending'),
            default => null,
        };
    }

    // ── GET /branch-operations/reports/counts/{type} ──────────────────────────
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
        'sessions' => ['#', 'الفرع', 'أُنشئت بواسطة', 'الحالة', 'عدد الأصناف', 'الدقة %', 'التاريخ'],
        'approved' => ['#', 'الفرع', 'أُنشئت بواسطة', 'الحالة', 'عدد الأصناف', 'الدقة %', 'التاريخ'],
        'pending' => ['#', 'الفرع', 'أُنشئت بواسطة', 'الحالة', 'عدد الأصناف', 'الدقة %', 'التاريخ'],
        'branch-accuracy' => ['الفرع', 'عدد الأصناف المجرودة', 'الدقة %'],
        'employee-accuracy' => ['الموظف', 'عدد الأصناف المجرودة', 'الدقة %'],
        'product-variance' => ['المنتج', 'الكود', 'مرات الجرد', 'مرات الاختلاف', 'إجمالي الفروقات المطلقة'],
        'biggest-differences' => ['#الجلسة', 'الفرع', 'المنتج', 'الكود', 'كمية النظام', 'الكمية الفعلية', 'الفرق'],
        'accuracy-trend' => ['#الجلسة', 'الفرع', 'التاريخ', 'الدقة %'],
    ];

    private const TITLES = [
        'sessions' => 'جلسات الجرد', 'approved' => 'جلسات الجرد المعتمدة', 'pending' => 'جلسات الجرد المعلّقة',
        'branch-accuracy' => 'دقة الجرد حسب الفرع', 'employee-accuracy' => 'دقة الجرد حسب الموظف',
        'product-variance' => 'تباين الجرد حسب المنتج', 'biggest-differences' => 'أكبر الفروقات', 'accuracy-trend' => 'اتجاه دقة الجرد',
    ];

    private function toRow(string $type, array $r): array
    {
        return match ($type) {
            'sessions', 'approved', 'pending' => [$r['session_id'], $r['shop_name'], $r['created_by'], $r['status'], $r['items_count'], $r['accuracy_percent'] ?? '—', $r['created_at']],
            'branch-accuracy' => [$r['shop_name'], $r['items_counted'], $r['accuracy_percent'] ?? '—'],
            'employee-accuracy' => [$r['user_name'], $r['items_counted'], $r['accuracy_percent'] ?? '—'],
            'product-variance' => [$r['product_name'], $r['sku'], $r['times_counted'], $r['times_diff'], $r['total_abs_diff']],
            'biggest-differences' => [$r['session_id'], $r['shop_name'], $r['product_name'], $r['sku'], $r['system_quantity'], $r['physical_quantity'], $r['difference']],
            'accuracy-trend' => [$r['session_id'], $r['shop_name'], $r['date'], $r['accuracy_percent'] ?? '—'],
            default => [],
        };
    }

    // ── GET /branch-operations/reports/counts/{type}/export?format=pdf|excel ──
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
