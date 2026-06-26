<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Safe;
use App\Models\SafeBalance;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    // GET /api/admin/dashboard
    public function index(Request $request)
    {
        $today      = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $last7Start = now()->subDays(6)->toDateString();

        // ── Today KPIs ───────────────────────────────────────────────
        $todayApproved  = Invoice::where('status', 'approved')->whereDate('date', $today);
        $todayRevenue   = (clone $todayApproved)->sum('total_amount');
        $todayCount     = (clone $todayApproved)->count();
        $activeShops    = (clone $todayApproved)->distinct('shop_id')->count('shop_id');

        $pendingBase    = Invoice::where('status', 'pending');
        $pendingCount   = (clone $pendingBase)->count();
        $pendingValue   = (clone $pendingBase)->sum('total_amount');

        // Total cash across all active safes (sum of all balances regardless of currency)
        $totalCash      = SafeBalance::sum('balance');

        // ── System entity counts ─────────────────────────────────────
        $totalShops    = Shop::count();
        $totalProducts = Product::count();
        $totalStaff    = User::whereIn('role', ['sales', 'manager'])->count();
        $totalCustomers= Customer::count();

        // ── Month comparison ─────────────────────────────────────────
        $lastMonthStart   = now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd     = now()->subMonth()->endOfMonth()->toDateString();
        $thisMonthRevenue = Invoice::where('status', 'approved')->whereBetween('date', [$monthStart, $today])->sum('total_amount');
        $lastMonthRevenue = Invoice::where('status', 'approved')->whereBetween('date', [$lastMonthStart, $lastMonthEnd])->sum('total_amount');
        $changePct        = $lastMonthRevenue > 0
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : null;

        // ── 7-day revenue trend ──────────────────────────────────────
        $trend = Invoice::where('status', 'approved')
            ->whereBetween('date', [$last7Start, $today])
            ->selectRaw('date, COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as invoice_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'          => (string) $r->date,
                'revenue'       => round((float) $r->revenue, 2),
                'invoice_count' => (int)    $r->invoice_count,
            ]);

        // ── Top 5 shops this month ───────────────────────────────────
        $topShops = Invoice::where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$monthStart, $today])
            ->join('shops', 'invoices.shop_id', '=', 'shops.id')
            ->selectRaw('
                invoices.shop_id,
                shops.name as shop_name,
                COUNT(*) as invoice_count,
                COALESCE(SUM(invoices.total_amount), 0) as revenue
            ')
            ->groupBy('invoices.shop_id', 'shops.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'shop_id'       => (int)   $r->shop_id,
                'shop_name'     => $r->shop_name,
                'invoice_count' => (int)   $r->invoice_count,
                'revenue'       => round((float) $r->revenue, 2),
            ]);

        // ── Recent 10 invoices ───────────────────────────────────────
        $recentInvoices = Invoice::with(['shop:id,name', 'seller:id,name', 'customer:id,name'])
            ->latest('date')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($inv) => [
                'id'            => $inv->id,
                'date'          => $inv->date?->toDateString(),
                'shop_name'     => $inv->shop?->name    ?? '—',
                'seller_name'   => $inv->seller?->name  ?? '—',
                'customer_name' => $inv->customer?->name ?? 'زبون عابر',
                'total_amount'  => round((float) $inv->total_amount, 2),
                'status'        => $inv->status,
            ]);

        // ── Safe balances per shop ───────────────────────────────────
        $safeBalances = Safe::with(['shop:id,name', 'safeType:id,name', 'balances.currency'])
            ->whereNotNull('shop_id')
            ->orderBy('shop_id')
            ->get()
            ->map(fn ($safe) => [
                'shop_name' => $safe->shop?->name    ?? '—',
                'type_name' => $safe->safeType?->name ?? '—',
                'is_active' => (bool) $safe->is_active,
                'balances'  => $safe->balances->map(fn ($b) => [
                    'currency' => $b->currency?->code ?? $b->currency?->name ?? '—',
                    'symbol'   => $b->currency?->symbol ?? '',
                    'balance'  => round((float) $b->balance, 2),
                ])->values(),
            ]);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'today' => [
                    'revenue'       => round((float) $todayRevenue, 2),
                    'invoice_count' => (int) $todayCount,
                    'active_shops'  => (int) $activeShops,
                    'pending_count' => (int) $pendingCount,
                    'pending_value' => round((float) $pendingValue, 2),
                    'total_cash'    => round((float) $totalCash, 2),
                ],
                'counts' => [
                    'shops'     => (int) $totalShops,
                    'products'  => (int) $totalProducts,
                    'staff'     => (int) $totalStaff,
                    'customers' => (int) $totalCustomers,
                ],
                'month_comparison' => [
                    'this_month' => round((float) $thisMonthRevenue, 2),
                    'last_month' => round((float) $lastMonthRevenue, 2),
                    'change_pct' => $changePct,
                ],
                'trend'          => $trend,
                'top_shops'      => $topShops,
                'recent_invoices'=> $recentInvoices,
                'safe_balances'  => $safeBalances,
            ],
        ]);
    }
}
