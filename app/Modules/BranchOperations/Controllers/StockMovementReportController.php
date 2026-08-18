<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Services\StockMovementService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

/**
 * Phase 4.11 — Stock Movement Report. Every row comes from
 * StockMovementService (the one source of truth shared with the Inventory
 * Ledger and Inventory Audit Report) — this controller only aggregates and
 * presents it, never re-derives movement data itself.
 *
 * Opening/Closing Balance and the running balance column are only
 * meaningful when scoped to a single product (a "statement of account" for
 * one item) — without a product filter they're omitted rather than shown
 * as a misleading cross-product sum.
 */
class StockMovementReportController extends Controller
{
    private const TYPE_LABELS = [
        'purchase' => 'مشتريات', 'sale' => 'مبيعات', 'transfer_in' => 'نقل وارد', 'transfer_out' => 'نقل صادر',
        'waste' => 'هالك', 'adjustment_positive' => 'تسوية (+)', 'adjustment_negative' => 'تسوية (-)', 'count_adjustment' => 'تسوية جرد',
    ];

    public function __construct(private StockMovementService $movements, private ReportExportService $exportService) {}

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
        $scoped = ! empty($filters['product_id']);

        $openingBalance = $scoped ? $this->movements->openingBalance($filters, $from) : null;
        $rows = $this->movements->movements(array_merge($filters, ['from' => $from, 'to' => $to]));

        $running = $openingBalance;
        $detail = $rows->map(function ($r) use (&$running, $scoped) {
            if ($scoped) {
                $running = round($running + $r->quantity_in - $r->quantity_out, 3);
            }
            return [
                'date' => $r->movement_date, 'type' => $r->movement_type, 'type_label' => self::TYPE_LABELS[$r->movement_type] ?? $r->movement_type,
                'reference_number' => $r->reference_number, 'product_name' => $r->product_name, 'sku' => $r->product_sku, 'unit' => $r->unit,
                'shop_name' => $r->shop_name, 'source_shop_name' => $r->source_shop_name, 'destination_shop_name' => $r->destination_shop_name,
                'quantity_in' => $r->quantity_in, 'quantity_out' => $r->quantity_out,
                'running_balance' => $scoped ? $running : null,
                'unit_cost' => $r->unit_cost !== null ? round((float) $r->unit_cost, 2) : null,
                'total_value' => $r->total_value !== null ? round((float) $r->total_value, 2) : null,
                'employee' => $r->user_name, 'notes' => $r->notes,
            ];
        });

        $totalsByType = collect(StockMovementService::TYPES)->mapWithKeys(function ($type) use ($rows) {
            $typeRows = $rows->where('movement_type', $type);
            return [$type => [
                'label' => self::TYPE_LABELS[$type],
                'quantity_in' => round((float) $typeRows->sum('quantity_in'), 3),
                'quantity_out' => round((float) $typeRows->sum('quantity_out'), 3),
            ]];
        });

        $totalIn = round((float) $rows->sum('quantity_in'), 3);
        $totalOut = round((float) $rows->sum('quantity_out'), 3);
        $closingBalance = $scoped ? round($openingBalance + $totalIn - $totalOut, 3) : null;

        // Charts — daily net movement, by category, by branch (derived from the same $rows, never re-queried).
        $dailyMovement = $rows->groupBy(fn ($r) => substr((string) $r->movement_date, 0, 10))
            ->map(fn ($g, $date) => ['date' => $date, 'in' => round((float) $g->sum('quantity_in'), 3), 'out' => round((float) $g->sum('quantity_out'), 3)])
            ->values()->sortBy('date')->values();

        $byCategory = $rows->groupBy('product_type')
            ->map(fn ($g, $type) => ['category' => $type ?: 'غير محدد', 'in' => round((float) $g->sum('quantity_in'), 3), 'out' => round((float) $g->sum('quantity_out'), 3)])
            ->values();

        $byBranch = $rows->groupBy(fn ($r) => $r->shop_name ?? 'غير محدد')
            ->map(fn ($g, $shop) => ['branch' => $shop, 'in' => round((float) $g->sum('quantity_in'), 3), 'out' => round((float) $g->sum('quantity_out'), 3)])
            ->values();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'scoped_to_product' => $scoped,
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'totals_by_type' => $totalsByType,
            'kpis' => [
                'total_in' => $totalIn, 'total_out' => $totalOut, 'net_movement' => round($totalIn - $totalOut, 3),
                'current_balance' => $closingBalance,
            ],
            'rows' => $detail->values(),
            'charts' => ['daily_movement' => $dailyMovement, 'by_category' => $byCategory, 'by_branch' => $byBranch],
        ];
    }

    // ── GET /branch-operations/reports/stock-movement ────────────────────────
    public function data(Request $request)
    {
        return response()->json(['message' => 'ok', 'data' => $this->buildReport($this->filters($request))]);
    }

    // ── GET /branch-operations/reports/stock-movement/export?format=pdf|excel ──
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $report = $this->buildReport($filters);

        $columns = ['التاريخ', 'النوع', 'المرجع', 'المنتج', 'الكود', 'الفرع', 'وارد', 'صادر', 'الرصيد الجاري', 'القيمة الإجمالية', 'الموظف'];
        $tableRows = $report['rows']->map(fn ($r) => [
            $r['date'], $r['type_label'], $r['reference_number'], $r['product_name'], $r['sku'], $r['shop_name'],
            $r['quantity_in'], $r['quantity_out'], $r['running_balance'] ?? '—', $r['total_value'] ?? '—', $r['employee'],
        ])->all();

        $filterLabels = array_filter([
            'من' => $report['period']['from'], 'إلى' => $report['period']['to'],
            'الرصيد الافتتاحي' => $report['opening_balance'], 'الرصيد الختامي' => $report['closing_balance'],
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير حركة المخزون', $columns, $tableRows, $filterLabels)
            : $this->exportService->pdf('تقرير حركة المخزون', $columns, $tableRows, $filterLabels);
    }
}
