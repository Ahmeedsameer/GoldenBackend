<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monthly revenue vs. estimated cost, ties Sales data together with the
 * Pricing module's tracked `purchase_cost` per product — same live-cost
 * approximation used by PricingService::profit_after and the Branch
 * Comparison report (no COGS is captured at sale time anywhere in this
 * schema, so this is always an "estimated" profit, never exact).
 */
class AdminMonthlyProfitController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        // Default: last 12 months, so the trend chart has something to show.
        return [now()->subMonths(11)->startOfMonth()->toDateString(), now()->toDateString()];
    }

    private function monthlyRows(string $from, string $to, ?int $shopId)
    {
        $q = DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to]);

        if ($shopId) {
            $q->where('inv.shop_id', $shopId);
        }

        return $q->selectRaw("
                DATE_FORMAT(inv.date, '%Y-%m') as month,
                SUM(ii.quantity * ii.price) as revenue,
                SUM(ii.quantity * COALESCE(p.purchase_cost, 0)) as estimated_cost
            ")
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    // ── GET /api/admin/reports/monthly-profit ────────────────────────────────
    public function trend(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $shopId = $request->filled('shop_id') ? (int) $request->get('shop_id') : null;

        $rows = $this->monthlyRows($from, $to, $shopId)->map(function ($r) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->estimated_cost;
            $profit = $revenue - $cost;
            return [
                'month' => $r->month,
                'revenue' => round($revenue, 2),
                'estimated_cost' => round($cost, 2),
                'estimated_profit' => round($profit, 2),
                'profit_margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            ];
        })->values();

        $totalRevenue = $rows->sum('revenue');
        $totalCost = $rows->sum('estimated_cost');
        $totalProfit = $totalRevenue - $totalCost;

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'months' => $rows,
                'totals' => [
                    'revenue' => round($totalRevenue, 2),
                    'estimated_cost' => round($totalCost, 2),
                    'estimated_profit' => round($totalProfit, 2),
                    'profit_margin_percent' => $totalRevenue > 0 ? round($totalProfit / $totalRevenue * 100, 1) : null,
                ],
            ],
        ]);
    }

    // ── GET /api/admin/reports/monthly-profit/export?format=pdf|excel ───────
    public function export(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $shopId = $request->filled('shop_id') ? (int) $request->get('shop_id') : null;

        $columns = ['الشهر', 'الإيرادات', 'التكلفة التقديرية', 'الربح التقديري', 'هامش الربح %'];
        $rows = $this->monthlyRows($from, $to, $shopId)->map(function ($r) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->estimated_cost;
            $profit = $revenue - $cost;
            return [$r->month, round($revenue, 2), round($cost, 2), round($profit, 2), $revenue > 0 ? round($profit / $revenue * 100, 1) : 0];
        })->values()->all();

        $filters = array_filter([
            'من' => $from, 'إلى' => $to,
            'الفرع' => $shopId ? Shop::find($shopId)?->name : null,
        ]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير الربح الشهري', $columns, $rows, $filters, [1, 2, 3])
            : $this->exportService->pdf('تقرير الربح الشهري', $columns, $rows, $filters);
    }
}
