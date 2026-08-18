<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Modules\BranchOperations\Services\StockMovementService;
use App\Services\Reports\ReportExportService;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;

/**
 * Phase 4.14 — Inventory Audit Report. Every row comes from
 * StockMovementService (same source as 4.11/4.12) reframed as an audit
 * trail: each row gets an Old Quantity / New Quantity computed as the
 * running balance of its own (product, shop) pair immediately before/after
 * the event — no new movement query, just a different presentation of the
 * same rows. "Count Sessions" is the one KPI that queries
 * InventoryCountSession directly, since a count session itself moves no
 * inventory (only the adjustment it may produce does, already covered by
 * the movement rows) — it is a genuinely distinct entity, not a duplicated
 * movement source.
 */
class InventoryAuditReportController extends Controller
{
    private const OPERATION_LABELS = [
        'purchase' => 'شراء', 'sale' => 'بيع', 'transfer_in' => 'نقل وارد', 'transfer_out' => 'نقل صادر',
        'waste' => 'هالك', 'adjustment_positive' => 'تسوية يدوية (+)', 'adjustment_negative' => 'تسوية يدوية (-)',
        'count_adjustment' => 'تسوية جرد',
    ];

    public function __construct(private StockMovementService $movements, private ReportExportService $exportService, private WarehouseResolver $warehouse) {}

    private function filters(Request $request): array
    {
        return [
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'shop_id' => $request->filled('shop_id') ? $request->get('shop_id') : null,
            'product_id' => $request->filled('product_id') ? (int) $request->get('product_id') : null,
            'category_id' => $request->filled('category_id') ? (int) $request->get('category_id') : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->get('supplier_id') : null,
        ];
    }

    private function buildReport(array $filters): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $scopedFilters = array_merge($filters, ['from' => $from, 'to' => $to]);

        $rows = $this->movements->movements($scopedFilters);

        // Group by (product_id, shop_id) so Old/New Quantity reflect the running
        // balance of THAT specific product-at-location, exactly like the
        // Inventory Ledger does for a single product — just done for every
        // product/shop pair present in the window at once.
        $groups = $rows->groupBy(fn ($r) => ($r->product_id ?? 'null') . '|' . ($r->shop_id ?? 'null'));

        $detail = collect();

        foreach ($groups as $key => $groupRows) {
            [$productId, $shopId] = explode('|', $key);
            $productId = $productId === 'null' ? null : (int) $productId;
            $shopId = $shopId === 'null' ? 0 : (int) $shopId; // 0 = warehouse convention

            $groupFilters = array_merge($filters, ['product_id' => $productId, 'shop_id' => $shopId]);
            $running = $this->movements->openingBalance($groupFilters, $from);

            foreach ($groupRows->sortBy('movement_date')->values() as $r) {
                $old = $running;
                $running = round($running + $r->quantity_in - $r->quantity_out, 3);

                $detail->push([
                    'date' => $r->movement_date,
                    'user' => $r->user_name,
                    'shop_name' => $r->shop_name,
                    'product_name' => $r->product_name,
                    'sku' => $r->product_sku,
                    'operation' => self::OPERATION_LABELS[$r->movement_type] ?? $r->movement_type,
                    'movement_type' => $r->movement_type,
                    'old_quantity' => $old,
                    'new_quantity' => $running,
                    'difference' => round($r->quantity_in - $r->quantity_out, 3),
                    'reason' => $r->notes,
                    'reference_number' => $r->reference_number,
                ]);
            }
        }

        $detail = $detail->sortBy('date')->values();

        $countSessionsQuery = InventoryCountSession::whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        if ($filters['shop_id'] !== null && $filters['shop_id'] !== '') {
            $shopId = (int) $filters['shop_id'];
            // InventoryCountSession.shop_id is always a real Shop id (count sessions have no
            // NULL-shop convention) — only the legacy "0" sentinel needs resolving to the
            // real Main Warehouse id; a real shop id (including the warehouse's) passes through.
            $countSessionsQuery->where('shop_id', $shopId === 0 ? $this->warehouse->warehouseShopId() : $shopId);
        }

        $kpis = [
            'total_operations' => $rows->count(),
            'adjustments' => $rows->whereIn('movement_type', ['adjustment_positive', 'adjustment_negative', 'count_adjustment'])->count(),
            'waste_events' => $rows->where('movement_type', 'waste')->count(),
            'transfer_events' => $rows->where('movement_type', 'transfer_out')->count(),
            'count_sessions' => $countSessionsQuery->count(),
        ];

        return [
            'period' => ['from' => $from, 'to' => $to],
            'kpis' => $kpis,
            'rows' => $detail,
        ];
    }

    // ── GET /branch-operations/reports/inventory-audit ────────────────────────
    public function data(Request $request)
    {
        return response()->json(['message' => 'ok', 'data' => $this->buildReport($this->filters($request))]);
    }

    // ── GET /branch-operations/reports/inventory-audit/export?format=pdf|excel ──
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $report = $this->buildReport($filters);

        $columns = ['التاريخ', 'المستخدم', 'الفرع', 'المنتج', 'العملية', 'الكمية القديمة', 'الكمية الجديدة', 'الفرق', 'السبب', 'المرجع'];
        $tableRows = $report['rows']->map(fn ($r) => [
            $r['date'], $r['user'] ?? '—', $r['shop_name'], $r['product_name'], $r['operation'],
            $r['old_quantity'], $r['new_quantity'], $r['difference'], $r['reason'] ?? '—', $r['reference_number'],
        ])->all();

        $filterLabels = array_filter([
            'من' => $report['period']['from'], 'إلى' => $report['period']['to'],
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير التدقيق على المخزون', $columns, $tableRows, $filterLabels)
            : $this->exportService->pdf('تقرير التدقيق على المخزون', $columns, $tableRows, $filterLabels);
    }
}
