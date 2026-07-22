<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Side-by-side branch comparison — revenue, estimated profit, most active
 * seller, and most-used oil/bottle, per shop for the selected period.
 *
 * "Estimated profit" reuses the same live-cost approximation already used by
 * the Pricing module (PricingService::profit_after: selling price minus the
 * product's current `purchase_cost`) — there is no cost-at-sale-time field
 * captured anywhere in this schema, so this is a live-cost estimate, never a
 * precise historical COGS figure. Labeled "تقديري" (estimated) everywhere.
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
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->join('shops as s', 's.id', '=', 'inv.shop_id')
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->selectRaw('
                inv.shop_id, s.name as shop_name,
                SUM(ii.quantity * ii.price) as revenue,
                SUM(ii.quantity * COALESCE(p.purchase_cost, 0)) as estimated_cost
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
        return DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->where('ii.role', $role)
            ->where('inv.status', 'approved')
            ->whereBetween('inv.date', [$from, $to])
            ->selectRaw('inv.shop_id, p.id as product_id, p.name as product_name, SUM(ii.quantity) as qty')
            ->groupBy('inv.shop_id', 'p.id', 'p.name')
            ->get()
            ->groupBy('shop_id')
            ->map(fn ($rows) => $rows->sortByDesc('qty')->first());
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

        $columns = ['الفرع', 'عدد الفواتير', 'الإيرادات', 'التكلفة التقديرية', 'الربح التقديري', 'هامش الربح %'];
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
