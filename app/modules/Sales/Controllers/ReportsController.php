<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentMethod;
use App\Models\Safe;
use App\Models\SafeTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    // ────────────────────────────────────────────────────────────────
    // Resolve date range from ?period=today|week|month|year
    // ────────────────────────────────────────────────────────────────
    private function parseDates(Request $request): array
    {
        // Explicit date range always takes priority over the period preset
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

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/sales
    // ────────────────────────────────────────────────────────────────
    public function salesSummary(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        $summary = Invoice::where('shop_id', $shopId)
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(AVG(total_amount), 0) as avg_invoice
            ')
            ->first();

        $pendingCount = Invoice::where('shop_id', $shopId)
            ->where('status', 'pending')
            ->count();

        // Payment-method breakdown (Cash vs Visa …) for approved invoices in the
        // period. Amounts are converted to EGP via each currency's rate so the
        // totals are directly comparable.
        $paymentMethods = $this->paymentMethodBreakdown($shopId, $from, $to);

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'period'          => ['from' => $from, 'to' => $to],
                'invoice_count'   => (int)   ($summary->invoice_count ?? 0),
                'total_revenue'   => round((float) ($summary->total_revenue ?? 0), 2),
                'avg_invoice'     => round((float) ($summary->avg_invoice   ?? 0), 2),
                'pending_count'   => $pendingCount,
                'payment_methods' => $paymentMethods,
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Per-payment-method totals for approved invoices in the shop/period.
    // Returns one row per ACTIVE admin-managed payment method (0 when
    // none used), so the UI renders a stable set of cards — same
    // convention as before, now driven by the payment_methods table
    // instead of the old hardcoded 2-value enum.
    // ────────────────────────────────────────────────────────────────
    private function paymentMethodBreakdown(int $shopId, string $from, string $to): array
    {
        $rows = \App\Models\InvoicePayment::query()
            ->join('invoices',   'invoice_payments.invoice_id',  '=', 'invoices.id')
            ->join('currencies', 'invoice_payments.currency_id', '=', 'currencies.id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status',  'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->whereNotNull('invoice_payments.payment_method_id')
            ->selectRaw('
                invoice_payments.payment_method_id                                     as method_id,
                COUNT(*)                                                                as payment_count,
                COALESCE(SUM(invoice_payments.amount * currencies.rate), 0)             as amount_egp
            ')
            ->groupBy('invoice_payments.payment_method_id')
            ->get()
            ->keyBy('method_id');

        return PaymentMethod::where('is_active', true)->orderBy('name')->get()
            ->map(function (PaymentMethod $method) use ($rows) {
                $row = $rows->get($method->id);
                return [
                    'method'        => $method->id,
                    'label'         => $method->name,
                    'amount_egp'    => round((float) ($row->amount_egp    ?? 0), 2),
                    'payment_count' => (int)   ($row->payment_count ?? 0),
                ];
            })->all();
    }

    /**
     * GET /api/manager/reports/payment-methods-breakdown?date=YYYY-MM-DD
     * Per-payment-method totals for the manager's own shop on a single day —
     * powers the Safe Reconciliation page's breakdown table (no real
     * "shift/session" concept exists in this codebase; this is deliberately
     * a same-day snapshot, not a period-boundary/close action). Reuses
     * paymentMethodBreakdown() exactly — no new aggregation logic.
     */
    public function paymentMethodsForDate(Request $request)
    {
        $shopId = Auth::user()->shop_id;
        $date = $request->get('date', now()->toDateString());

        return response()->json([
            'message' => 'ok',
            'data' => ['date' => $date, 'methods' => $this->paymentMethodBreakdown($shopId, $date, $date)],
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/sellers
    // ────────────────────────────────────────────────────────────────
    public function sellerPerformance(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        $rows = Invoice::from('invoices')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->join('users', 'invoices.seller_id', '=', 'users.id')
            ->selectRaw('
                users.id        as seller_id,
                users.name      as seller_name,
                COUNT(invoices.id)                      as invoice_count,
                COALESCE(SUM(invoices.total_amount), 0) as total_revenue,
                COALESCE(AVG(invoices.total_amount), 0) as avg_invoice
            ')
            ->groupBy('invoices.seller_id', 'users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($r) => [
                'seller_id'     => $r->seller_id,
                'seller_name'   => $r->seller_name,
                'invoice_count' => (int)   $r->invoice_count,
                'total_revenue' => round((float) $r->total_revenue, 2),
                'avg_invoice'   => round((float) $r->avg_invoice,   2),
            ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/products
    // ────────────────────────────────────────────────────────────────
    public function topProducts(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        $rows = InvoiceItem::from('invoice_items')
            ->join('invoices',  'invoice_items.invoice_id',  '=', 'invoices.id')
            ->join('products',  'invoice_items.product_id',  '=', 'products.id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status',  'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->selectRaw('
                products.id     as product_id,
                products.name   as product_name,
                products.scalar as scalar,
                COALESCE(SUM(invoice_items.quantity), 0)                       as total_qty,
                COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue
            ')
            ->groupBy('invoice_items.product_id', 'products.id', 'products.name', 'products.scalar')
            ->orderByDesc('total_revenue')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'product_id'    => $r->product_id,
                'product_name'  => $r->product_name,
                'scalar'        => $r->scalar,
                'total_qty'     => round((float) $r->total_qty,     3),
                'total_revenue' => round((float) $r->total_revenue, 2),
            ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/inventory
    // ────────────────────────────────────────────────────────────────
    public function inventoryStatus(Request $request)
    {
        $shopId = Auth::user()->shop_id;

        $goods = Goods::where('shop_id', $shopId)
            ->with(['supplyItem.product.category'])
            ->orderBy('current_quantity')
            ->get()
            ->map(fn ($g) => [
                'goods_id'         => $g->id,
                'product_id'       => $g->supplyItem->product->id   ?? null,
                'product_name'     => $g->supplyItem->product->name ?? '—',
                'sku'              => $g->supplyItem->product->sku  ?? '—',
                'scalar'           => $g->supplyItem->product->scalar ?? '',
                'category_name'    => $g->supplyItem->product->category->name ?? '—',
                'current_quantity' => $g->current_quantity,
                'date'             => $g->date,
            ]);

        return response()->json(['message' => 'ok', 'data' => $goods]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/customers
    // Top customers by spend + unique customer count for period
    // ────────────────────────────────────────────────────────────────
    public function topCustomers(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        // Top customers
        $topCustomers = Invoice::from('invoices')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status',  'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->leftJoin('customers', 'invoices.customer_id', '=', 'customers.id')
            ->selectRaw('
                customers.id        as customer_id,
                customers.name      as customer_name,
                customers.phone     as customer_phone,
                COUNT(invoices.id)                      as visit_count,
                COALESCE(SUM(invoices.total_amount), 0) as total_spent
            ')
            ->groupBy('invoices.customer_id', 'customers.id', 'customers.name', 'customers.phone')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'customer_id'    => $r->customer_id,
                'customer_name'  => $r->customer_name  ?? 'زبون عابر',
                'customer_phone' => $r->customer_phone ?? '—',
                'visit_count'    => (int)   $r->visit_count,
                'total_spent'    => round((float) $r->total_spent, 2),
            ]);

        // Summary stats for the period
        $stats = Invoice::where('shop_id', $shopId)
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COUNT(DISTINCT customer_id) as unique_customers,
                COUNT(*)                    as total_invoices
            ')
            ->first();

        // All-time registered customer count
        $registeredTotal = \App\Models\Customer::count();

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'period'           => ['from' => $from, 'to' => $to],
                'unique_customers' => (int) ($stats->unique_customers ?? 0),
                'total_invoices'   => (int) ($stats->total_invoices   ?? 0),
                'registered_total' => $registeredTotal,
                'top_customers'    => $topCustomers,
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/sales/trend
    // Daily revenue & invoice count for charting
    // ────────────────────────────────────────────────────────────────
    public function salesTrend(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        $rows = Invoice::where('shop_id', $shopId)
            ->where('status', 'approved')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'          => (string) $r->date,
                'invoice_count' => (int)   $r->invoice_count,
                'revenue'       => round((float) $r->revenue, 2),
            ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/inventory/movements
    // Per-product: qty received (supplies) + qty sold (invoices) in period
    // ────────────────────────────────────────────────────────────────
    public function inventoryMovements(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        // Stock IN — supply items whose goods row belongs to this shop,
        // where the parent supply date falls within the period
        $supplyIn = DB::table('supply_items')
            ->join('supplies', 'supply_items.supply_id', '=', 'supplies.id')
            ->join('goods', function ($join) use ($shopId) {
                $join->on('goods.supply_item_id', '=', 'supply_items.id')
                     ->where('goods.shop_id', $shopId);
            })
            ->whereBetween('supplies.date', [$from, $to])
            ->selectRaw('supply_items.product_id, COALESCE(SUM(supply_items.quantity), 0) as qty_in')
            ->groupBy('supply_items.product_id')
            ->get()
            ->keyBy('product_id');

        // Stock OUT — approved invoice items for this shop in the period
        $salesOut = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->selectRaw('invoice_items.product_id, COALESCE(SUM(invoice_items.quantity), 0) as qty_sold')
            ->groupBy('invoice_items.product_id')
            ->get()
            ->keyBy('product_id');

        // Build per-product rows (one row per product, summing duplicate goods entries)
        $seen      = [];
        $movements = [];

        $goods = Goods::where('shop_id', $shopId)
            ->with(['supplyItem.product.category'])
            ->get();

        foreach ($goods as $g) {
            $product = $g->supplyItem?->product;
            if (!$product) continue;
            $pid = $product->id;

            if (isset($seen[$pid])) {
                $movements[$seen[$pid]]['current_qty'] += (float) $g->current_quantity;
                continue;
            }

            $seen[$pid] = count($movements);
            $movements[] = [
                'product_id'    => $pid,
                'product_name'  => $product->name,
                'sku'           => $product->sku  ?? '—',
                'scalar'        => $product->scalar ?? '',
                'category_name' => $product->category->name ?? '—',
                'qty_in'        => round((float) ($supplyIn[$pid]->qty_in  ?? 0), 3),
                'qty_sold'      => round((float) ($salesOut[$pid]->qty_sold ?? 0), 3),
                'current_qty'   => round((float) $g->current_quantity, 3),
            ];
        }

        // Sort by qty_sold descending (most active first)
        usort($movements, fn ($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);

        return response()->json(['message' => 'ok', 'data' => array_values($movements)]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/reports/financial
    // Safe transaction breakdown + current safe balances
    // ────────────────────────────────────────────────────────────────
    public function financialSummary(Request $request)
    {
        $shopId      = Auth::user()->shop_id;
        [$from, $to] = $this->parseDates($request);

        $safeIds = Safe::where('shop_id', $shopId)->pluck('id');

        // Transaction totals by direction + type + currency
        $transactions = SafeTransaction::whereIn('safe_id', $safeIds)
            ->whereDate('safe_transactions.created_at', '>=', $from)
            ->whereDate('safe_transactions.created_at', '<=', $to)
            ->join('currencies', 'safe_transactions.currency_id', '=', 'currencies.id')
            ->selectRaw('
                safe_transactions.direction,
                safe_transactions.type,
                currencies.id     as currency_id,
                currencies.code   as currency_code,
                currencies.symbol as currency_symbol,
                currencies.rate   as currency_rate,
                COALESCE(SUM(safe_transactions.amount), 0) as total_amount
            ')
            ->groupBy(
                'safe_transactions.direction',
                'safe_transactions.type',
                'currencies.id',
                'currencies.code',
                'currencies.symbol',
                'currencies.rate',
            )
            ->orderBy('currencies.code')
            ->get()
            ->map(fn ($r) => [
                'direction'       => $r->direction,
                'type'            => $r->type,
                'currency_code'   => $r->currency_code,
                'currency_symbol' => $r->currency_symbol,
                'currency_rate'   => (float) $r->currency_rate,
                'total_amount'    => round((float) $r->total_amount, 2),
            ]);

        // Current safe balances
        $safes = Safe::where('shop_id', $shopId)
            ->with(['safeType', 'balances.currency'])
            ->get()
            ->map(fn ($safe) => [
                'safe_id'   => $safe->id,
                'safe_name' => $safe->safeType->name ?? '—',
                'safe_kind' => $safe->safeType->kind ?? 'physical',
                'balances'  => $safe->balances->map(fn ($b) => [
                    'currency_id'     => $b->currency->id,
                    'currency_code'   => $b->currency->code,
                    'currency_symbol' => $b->currency->symbol,
                    'currency_rate'   => (float) $b->currency->rate,
                    'balance'         => (float) $b->balance,
                ]),
            ]);

        // EGP-equivalent income / expense totals
        $incomeEGP  = 0.0;
        $expenseEGP = 0.0;
        foreach ($transactions as $row) {
            $egp = $row['total_amount'] * $row['currency_rate'];
            if ($row['direction'] === 'in')  $incomeEGP  += $egp;
            if ($row['direction'] === 'out') $expenseEGP += $egp;
        }

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'period'       => ['from' => $from, 'to' => $to],
                'income_egp'   => round($incomeEGP,  2),
                'expense_egp'  => round($expenseEGP, 2),
                'net_egp'      => round($incomeEGP - $expenseEGP, 2),
                'transactions' => $transactions,
                'safes'        => $safes,
            ],
        ]);
    }
}
