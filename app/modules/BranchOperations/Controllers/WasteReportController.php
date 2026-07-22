<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.8 — Waste Reports. "Waste by Supplier" is real, not fabricated:
 * it joins waste_record_batches -> goods -> supply_items -> supplies ->
 * suppliers (see WasteRecordBatch, added specifically so this report can
 * exist without inventing a relationship that wasn't there before).
 */
class WasteReportController extends Controller
{
    private const REASON_LABELS = [
        'broken' => 'كسر', 'expired' => 'انتهاء صلاحية', 'leakage' => 'تسرب', 'lost' => 'فقدان',
        'damaged_during_transfer' => 'تلف أثناء النقل', 'other' => 'أخرى',
    ];

    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }

    // ── GET /branch-operations/reports/waste/summary ─────────────────────────
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $base = WasteRecord::whereBetween('date', [$from, $to]);

        $stats = (clone $base)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(estimated_value),0) as value')->first();
        $topProduct = DB::table('waste_records as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('p.id, p.name, SUM(w.quantity) as qty')
            ->groupBy('p.id', 'p.name')->orderByDesc('qty')->first();

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'total_records' => (int) $stats->cnt,
                'total_quantity' => round((float) $stats->qty, 3),
                'total_cost' => round((float) $stats->value, 2),
                'top_waste_product' => $topProduct ? ['id' => $topProduct->id, 'name' => $topProduct->name, 'qty' => round((float) $topProduct->qty, 3)] : null,
            ],
        ]);
    }

    private function byProduct(string $from, string $to): array
    {
        return DB::table('waste_records as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, COUNT(*) as record_count, SUM(w.quantity) as qty, SUM(w.estimated_value) as value')
            ->groupBy('p.id', 'p.name', 'p.sku')->orderByDesc('value')->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function byCategory(string $from, string $to): array
    {
        return DB::table('waste_records as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw("COALESCE(c.id, 0) as category_id, COALESCE(c.name, 'بدون فئة') as category_name, COUNT(*) as record_count, SUM(w.quantity) as qty, SUM(w.estimated_value) as value")
            ->groupBy('c.id', 'c.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['category_id' => $r->category_id, 'category_name' => $r->category_name, 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function byBranch(string $from, string $to): array
    {
        return DB::table('waste_records as w')
            ->join('shops as s', 's.id', '=', 'w.shop_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('s.id as shop_id, s.name as shop_name, COUNT(*) as record_count, SUM(w.quantity) as qty, SUM(w.estimated_value) as value')
            ->groupBy('s.id', 's.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function byEmployee(string $from, string $to): array
    {
        return DB::table('waste_records as w')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('u.id as user_id, u.name as user_name, COUNT(*) as record_count, SUM(w.quantity) as qty, SUM(w.estimated_value) as value')
            ->groupBy('u.id', 'u.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'user_name' => $r->user_name, 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    /**
     * Waste by Supplier — the newly-enabled report. Value is recomputed per
     * batch (quantity * product.purchase_cost) rather than splitting the
     * parent record's estimated_value, since purchase_cost is a flat
     * per-unit figure — this stays mathematically consistent with how
     * WasteService computes the record-level total in the first place.
     */
    private function bySupplier(string $from, string $to): array
    {
        return DB::table('waste_record_batches as b')
            ->join('waste_records as w', 'w.id', '=', 'b.waste_record_id')
            ->join('goods as g', 'g.id', '=', 'b.goods_id')
            ->join('supply_items as si', 'si.id', '=', 'g.supply_item_id')
            ->join('supplies as sup', 'sup.id', '=', 'si.supply_id')
            ->join('suppliers as supr', 'supr.id', '=', 'sup.supplier_id')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('supr.id as supplier_id, supr.name as supplier_name, COUNT(DISTINCT w.id) as record_count, SUM(b.quantity) as qty, SUM(b.quantity * COALESCE(p.purchase_cost, 0)) as value')
            ->groupBy('supr.id', 'supr.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['supplier_id' => $r->supplier_id, 'supplier_name' => $r->supplier_name, 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function byReason(string $from, string $to): array
    {
        return WasteRecord::whereBetween('date', [$from, $to])
            ->selectRaw('reason, COUNT(*) as record_count, SUM(quantity) as qty, SUM(estimated_value) as value')
            ->groupBy('reason')->orderByDesc('value')->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'reason_label' => self::REASON_LABELS[$r->reason] ?? $r->reason, 'record_count' => (int) $r->record_count, 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function trend(string $from, string $to): array
    {
        $rows = WasteRecord::whereBetween('date', [$from, $to])
            ->selectRaw('date, SUM(quantity) as qty, SUM(estimated_value) as value')
            ->groupBy('date')->orderBy('date')->get()->keyBy(fn ($r) => $r->date->toDateString());

        $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $end = \Illuminate\Support\Carbon::parse($to)->startOfDay();
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $row = $rows->get($key);
            $out[] = ['date' => $key, 'qty' => $row ? round((float) $row->qty, 3) : 0, 'value' => $row ? round((float) $row->value, 2) : 0];
        }
        return $out;
    }

    private function topProducts(string $from, string $to): array
    {
        return DB::table('waste_records as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->whereBetween('w.date', [$from, $to])
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, SUM(w.quantity) as qty, SUM(w.estimated_value) as value')
            ->groupBy('p.id', 'p.name', 'p.sku')->orderByDesc('qty')->limit(15)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'qty' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2)])
            ->all();
    }

    private function build(string $type, string $from, string $to): ?array
    {
        return match ($type) {
            'by-product' => $this->byProduct($from, $to),
            'by-category' => $this->byCategory($from, $to),
            'by-branch' => $this->byBranch($from, $to),
            'by-employee' => $this->byEmployee($from, $to),
            'by-supplier' => $this->bySupplier($from, $to),
            'by-reason' => $this->byReason($from, $to),
            'trend' => $this->trend($from, $to),
            'top-products' => $this->topProducts($from, $to),
            default => null,
        };
    }

    // ── GET /branch-operations/reports/waste/{type} ───────────────────────────
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
        'by-product' => ['المنتج', 'الكود', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'by-category' => ['الفئة', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'by-branch' => ['الفرع', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'by-employee' => ['الموظف', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'by-supplier' => ['المورد', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'by-reason' => ['السبب', 'عدد السجلات', 'الكمية', 'القيمة التقديرية'],
        'trend' => ['التاريخ', 'الكمية', 'القيمة التقديرية'],
        'top-products' => ['المنتج', 'الكود', 'الكمية', 'القيمة التقديرية'],
    ];

    private const TITLES = [
        'by-product' => 'الهالك حسب المنتج', 'by-category' => 'الهالك حسب الفئة', 'by-branch' => 'الهالك حسب الفرع',
        'by-employee' => 'الهالك حسب الموظف', 'by-supplier' => 'الهالك حسب المورد', 'by-reason' => 'الهالك حسب السبب',
        'trend' => 'اتجاه الهالك', 'top-products' => 'أكثر المنتجات هالكاً',
    ];

    private function toRow(string $type, array $r): array
    {
        return match ($type) {
            'by-product', 'top-products' => [$r['product_name'], $r['sku'], $r['record_count'] ?? null, $r['qty'], $r['value']],
            'by-category' => [$r['category_name'], $r['record_count'], $r['qty'], $r['value']],
            'by-branch' => [$r['shop_name'], $r['record_count'], $r['qty'], $r['value']],
            'by-employee' => [$r['user_name'], $r['record_count'], $r['qty'], $r['value']],
            'by-supplier' => [$r['supplier_name'], $r['record_count'], $r['qty'], $r['value']],
            'by-reason' => [$r['reason_label'], $r['record_count'], $r['qty'], $r['value']],
            'trend' => [$r['date'], $r['qty'], $r['value']],
            default => [],
        };
    }

    // ── GET /branch-operations/reports/waste/{type}/export?format=pdf|excel ──
    public function export(Request $request, string $type)
    {
        [$from, $to] = $this->parseDates($request);
        $rows = $this->build($type, $from, $to);

        if ($rows === null || ! isset(self::COLUMNS[$type])) {
            abort(404, 'نوع تقرير غير معروف');
        }

        // 'top-products' rows don't carry record_count — normalize to the same 4-column shape declared above.
        if ($type === 'top-products') {
            $rows = array_map(fn ($r) => $r + ['record_count' => null], $rows);
            $columns = self::COLUMNS['by-product'];
            $tableRows = array_map(fn ($r) => [$r['product_name'], $r['sku'], $r['qty'], $r['value']], $rows);
        } else {
            $columns = self::COLUMNS[$type];
            $tableRows = array_map(fn ($r) => $this->toRow($type, $r), $rows);
        }

        $title = self::TITLES[$type];
        $filters = array_filter(['من' => $from, 'إلى' => $to]);
        $currencyCol = count($columns) - 1;

        return $request->input('format') === 'excel'
            ? $this->exportService->excel($title, $columns, $tableRows, $filters, [$currencyCol])
            : $this->exportService->pdf($title, $columns, $tableRows, $filters);
    }
}
