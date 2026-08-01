<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Side-by-side branch comparison — revenue, actual profit, most active
 * seller, and most-used oil/bottle, per shop for the selected period.
 *
 * Profit is read directly from each invoice_items row's own permanent
 * accounting snapshot (line_cost, set once at sale time from the exact FIFO
 * batch consumed — see SalesService::processItem() and
 * InvoiceItem::line_cost/line_profit), never from the product's current
 * purchase_cost. Real historical COGS, not a live-cost estimate.
 */
class AdminBranchComparisonController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        $period = $request->get('period', 'month');
        $from = match ($period) {
            'today' => now()->startOfDay()->toDateString(),
            'week'  => now()->startOfWeek()->toDateString(),
            'month' => now()->startOfMonth()->toDateString(),
            'year'  => now()->startOfYear()->toDateString(),
            default => now()->startOfMonth()->toDateString(),
        };
        return [$from, now()->toDateString()];
    }

    private function revenueAndCostByShop(string $from, string $to)
    {
        return DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->join('shops as s', 's.id', '=', 'inv.shop_id')
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->selectRaw('
                inv.shop_id, s.name as shop_name,
                SUM(ii.quantity * ii.price) as revenue,
                SUM(COALESCE(ii.line_cost, ii.quantity * ii.unit_cost)) as estimated_cost
            ')
            ->groupBy('inv.shop_id', 's.name')
            ->get();
    }

    private function topSellerByShop(string $from, string $to)
    {
        return DB::table('invoices as inv')
            ->join('users as u', 'u.id', '=', 'inv.seller_id')
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->selectRaw('inv.shop_id, inv.seller_id, u.name as seller_name, SUM(inv.total_amount) as revenue')
            ->groupBy('inv.shop_id', 'inv.seller_id', 'u.name')
            ->get()
            ->groupBy('shop_id')
            ->map(fn ($rows) => $rows->sortByDesc('revenue')->first());
    }

    private function topRoleProductByShop(string $from, string $to, string $role)
    {
        // Historical usage report — grouped by product_id (stable identity)
        // ONLY. Grouping by the live products.name too (as before) would
        // silently split one product's totals into two groups if it was
        // renamed mid-period, potentially picking the wrong "top" product by
        // a split vote. Display name comes from each row's own frozen
        // invoice_items.product_name snapshot, never the live products table.
        $rows = DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('ii.role', $role)
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->selectRaw('inv.shop_id, ii.product_id, SUM(ii.quantity) as qty')
            ->groupBy('inv.shop_id', 'ii.product_id')
            ->get()
            ->groupBy('shop_id')
            ->map(fn ($rows) => $rows->sortByDesc('qty')->first())
            ->filter();

        $productIds = $rows->pluck('product_id');
        if ($productIds->isEmpty()) {
            return $rows;
        }

        // Most recent snapshot name for each winning product within this same
        // period/role/shop — reflects what it was actually called during
        // these sales, not a later rename.
        $latestNames = DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('ii.role', $role)
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->whereIn('ii.product_id', $productIds)
            ->orderByDesc('inv.date')
            ->orderByDesc('ii.id')
            ->get(['ii.product_id', 'ii.product_name'])
            ->unique('product_id')
            ->keyBy('product_id');

        return $rows->map(function ($r) use ($latestNames) {
            $r->product_name = $latestNames[$r->product_id]->product_name ?? '—';
            return $r;
        });
    }

    // ── GET /api/admin/reports/branch-comparison ─────────────────────────────
    public function compare(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $revCost = $this->revenueAndCostByShop($from, $to);
        $topSellers = $this->topSellerByShop($from, $to);
        $topOils = $this->topRoleProductByShop($from, $to, 'oil');
        $topBottles = $this->topRoleProductByShop($from, $to, 'bottle');

        $invoiceCounts = DB::table('invoices')
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('shop_id, COUNT(*) as invoice_count')
            ->groupBy('shop_id')
            ->pluck('invoice_count', 'shop_id');

        $rows = $revCost->map(function ($r) use ($topSellers, $topOils, $topBottles, $invoiceCounts) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->estimated_cost;
            $profit = $revenue - $cost;
            $seller = $topSellers->get($r->shop_id);
            $oil = $topOils->get($r->shop_id);
            $bottle = $topBottles->get($r->shop_id);

            return [
                'shop_id' => (int) $r->shop_id,
                'shop_name' => $r->shop_name,
                'invoice_count' => (int) ($invoiceCounts->get($r->shop_id) ?? 0),
                'total_revenue' => round($revenue, 2),
                'estimated_cost' => round($cost, 2),
                'estimated_profit' => round($profit, 2),
                'profit_margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'top_seller' => $seller ? ['name' => $seller->seller_name, 'revenue' => round((float) $seller->revenue, 2)] : null,
                'top_oil' => $oil ? ['name' => $oil->product_name, 'qty' => round((float) $oil->qty, 3)] : null,
                'top_bottle' => $bottle ? ['name' => $bottle->product_name, 'qty' => round((float) $bottle->qty, 0)] : null,
            ];
        })->sortByDesc('total_revenue')->values();

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'branches' => $rows]]);
    }

    // ── GET /api/admin/reports/branch-comparison/export?format=pdf|excel ────
    public function export(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $revCost = $this->revenueAndCostByShop($from, $to);
        $invoiceCounts = DB::table('invoices')
            ->where('status', 'approved')->whereBetween('date', [$from, $to])
            ->selectRaw('shop_id, COUNT(*) as invoice_count')->groupBy('shop_id')->pluck('invoice_count', 'shop_id');

        $columns = ['الفرع', 'عدد الفواتير', 'الإيرادات', 'التكلفة الفعلية', 'الربح الفعلي', 'هامش الربح %'];
        $rows = $revCost->sortByDesc('revenue')->map(function ($r) use ($invoiceCounts) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->estimated_cost;
            $profit = $revenue - $cost;
            return [
                $r->shop_name, (int) ($invoiceCounts->get($r->shop_id) ?? 0),
                round($revenue, 2), round($cost, 2), round($profit, 2),
                $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
            ];
        })->values()->all();

        $filters = array_filter(['من' => $from, 'إلى' => $to]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('مقارنة الفروع', $columns, $rows, $filters, [2, 3, 4])
            : $this->exportService->pdf('مقارنة الفروع', $columns, $rows, $filters);
    }
}
