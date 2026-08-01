<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shop;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSalesReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    /** GET /api/admin/reports/sales/export?format=pdf|excel — the full invoice list for the selected period/filters. */
    public function export(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = Invoice::with(['shop:id,name', 'seller:id,name', 'customer:id,name,phone'])
            ->withCount('items')
            ->whereBetween('date', [$from, $to]);

        if ($request->filled('shop_id'))   { $q->where('shop_id', (int) $request->get('shop_id')); }
        if ($request->filled('seller_id')) { $q->where('seller_id', (int) $request->get('seller_id')); }
        if ($request->filled('status'))    { $q->where('status', $request->get('status')); }

        $invoices = $q->orderByDesc('date')->orderByDesc('id')->get();

        $columns = ['رقم الفاتورة', 'التاريخ', 'الفرع', 'البائع', 'العميل', 'عدد الأصناف', 'الإجمالي', 'الحالة'];
        $rows = $invoices->map(fn ($inv) => [
            $inv->id, $inv->date?->toDateString(), $inv->shop?->name ?? '—', $inv->seller?->name ?? '—',
            $inv->customer?->name ?? 'زبون عابر', $inv->items_count, round((float) $inv->total_amount, 2), $inv->status,
        ])->all();

        $filters = array_filter([
            'من' => $from, 'إلى' => $to,
            'الفرع' => $request->filled('shop_id') ? Shop::find($request->get('shop_id'))?->name : null,
        ]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير المبيعات', $columns, $rows, $filters, [6])
            : $this->exportService->pdf('تقرير المبيعات', $columns, $rows, $filters);
    }

    // ── Date range resolver ──────────────────────────────────────────
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

    // ── Optional shop scope helper ───────────────────────────────────
    private function maybeShop(Request $request, $query, string $col = 'shop_id')
    {
        if ($request->filled('shop_id')) {
            $query->where($col, (int) $request->get('shop_id'));
        }
        return $query;
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/summary
    // Top-level KPIs for the selected period + optional shop
    // ════════════════════════════════════════════════════════════════
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $base = Invoice::whereBetween('date', [$from, $to]);
        $this->maybeShop($request, $base);

        $approved = (clone $base)->where('status', 'approved');

        $stats = (clone $approved)->selectRaw('
            COUNT(*)                          as invoice_count,
            COALESCE(SUM(total_amount), 0)    as total_revenue,
            COALESCE(AVG(total_amount), 0)    as avg_invoice,
            COALESCE(MAX(total_amount), 0)    as max_invoice,
            COALESCE(MIN(total_amount), 0)    as min_invoice
        ')->first();

        // Total quantity sold across all approved invoice items
        $itemsSold = InvoiceItem::join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereBetween('invoices.date', [$from, $to])
            ->where('invoices.status', 'approved')
            ->when($request->filled('shop_id'), fn ($q) => $q->where('invoices.shop_id', (int) $request->get('shop_id')))
            ->sum('invoice_items.quantity');

        // Registered customers (non-null customer_id)
        $uniqueRegistered = (clone $approved)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        // Walk-in invoices (null customer_id)
        $walkInCount = (clone $approved)->whereNull('customer_id')->count();

        // Pending
        $pendingCount = (clone $base)->where('status', 'pending')->count();
        $pendingValue = (clone $base)->where('status', 'pending')->sum('total_amount');

        // Active shops that made at least one approved invoice in the period
        $activeShopCount = (clone $approved)->distinct('shop_id')->count('shop_id');

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period'             => ['from' => $from, 'to' => $to],
                'invoice_count'      => (int)    ($stats->invoice_count   ?? 0),
                'total_revenue'      => round((float) ($stats->total_revenue  ?? 0), 2),
                'avg_invoice'        => round((float) ($stats->avg_invoice    ?? 0), 2),
                'max_invoice'        => round((float) ($stats->max_invoice    ?? 0), 2),
                'min_invoice'        => round((float) ($stats->min_invoice    ?? 0), 2),
                'total_items_sold'   => round((float) $itemsSold, 3),
                'unique_customers'   => (int) $uniqueRegistered,
                'walk_in_count'      => (int) $walkInCount,
                'pending_count'      => (int) $pendingCount,
                'pending_value'      => round((float) $pendingValue, 2),
                'active_shops'       => (int) $activeShopCount,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/trend
    // Daily revenue + invoice count for the period
    // ════════════════════════════════════════════════════════════════
    public function trend(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = Invoice::where('status', 'approved')->whereBetween('date', [$from, $to]);
        $this->maybeShop($request, $q);

        $rows = $q->selectRaw('
                date,
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as revenue
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'          => (string) $r->date,
                'invoice_count' => (int)    $r->invoice_count,
                'revenue'       => round((float) $r->revenue, 2),
            ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/by-shop
    // Revenue breakdown per shop (cross-shop summary)
    // ════════════════════════════════════════════════════════════════
    public function byShop(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = Invoice::where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->join('shops', 'invoices.shop_id', '=', 'shops.id')
            ->selectRaw('
                invoices.shop_id,
                shops.name as shop_name,
                COUNT(*) as invoice_count,
                COALESCE(SUM(invoices.total_amount), 0) as total_revenue,
                COALESCE(AVG(invoices.total_amount), 0) as avg_invoice,
                COALESCE(MAX(invoices.total_amount), 0) as max_invoice,
                COUNT(DISTINCT invoices.seller_id) as seller_count,
                COUNT(DISTINCT invoices.customer_id) as unique_customers
            ')
            ->groupBy('invoices.shop_id', 'shops.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($r) => [
                'shop_id'         => (int)   $r->shop_id,
                'shop_name'       => $r->shop_name,
                'invoice_count'   => (int)   $r->invoice_count,
                'total_revenue'   => round((float) $r->total_revenue, 2),
                'avg_invoice'     => round((float) $r->avg_invoice, 2),
                'max_invoice'     => round((float) $r->max_invoice, 2),
                'seller_count'    => (int)   $r->seller_count,
                'unique_customers'=> (int)   $r->unique_customers,
            ]);

        $totalRevenue = $rows->sum('total_revenue');
        $rows = $rows->map(fn ($r) => array_merge($r, [
            'share' => $totalRevenue > 0 ? round(($r['total_revenue'] / $totalRevenue) * 100, 1) : 0,
        ]));

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/by-seller
    // Per-seller performance, optionally filtered to one shop
    // ════════════════════════════════════════════════════════════════
    public function bySeller(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = Invoice::where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->join('users',  'invoices.seller_id', '=', 'users.id')
            ->join('shops',  'invoices.shop_id',   '=', 'shops.id');

        $this->maybeShop($request, $q, 'invoices.shop_id');

        $rows = $q->selectRaw('
                invoices.seller_id,
                users.name  as seller_name,
                invoices.shop_id,
                shops.name  as shop_name,
                COUNT(*)    as invoice_count,
                COALESCE(SUM(invoices.total_amount), 0) as total_revenue,
                COALESCE(AVG(invoices.total_amount), 0) as avg_invoice,
                COALESCE(MAX(invoices.total_amount), 0) as max_invoice,
                COALESCE(MIN(invoices.total_amount), 0) as min_invoice,
                COUNT(DISTINCT invoices.customer_id)    as unique_customers
            ')
            ->groupBy('invoices.seller_id', 'users.name', 'invoices.shop_id', 'shops.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($r) => [
                'seller_id'       => (int)   $r->seller_id,
                'seller_name'     => $r->seller_name,
                'shop_id'         => (int)   $r->shop_id,
                'shop_name'       => $r->shop_name,
                'invoice_count'   => (int)   $r->invoice_count,
                'total_revenue'   => round((float) $r->total_revenue, 2),
                'avg_invoice'     => round((float) $r->avg_invoice, 2),
                'max_invoice'     => round((float) $r->max_invoice, 2),
                'min_invoice'     => round((float) $r->min_invoice, 2),
                'unique_customers'=> (int)   $r->unique_customers,
            ]);

        $totalRevenue = $rows->sum('total_revenue');
        $rows = $rows->map(fn ($r) => array_merge($r, [
            'share' => $totalRevenue > 0 ? round(($r['total_revenue'] / $totalRevenue) * 100, 1) : 0,
        ]));

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/by-product
    // Top 50 products by revenue (qty sold, avg price, invoice count)
    // ════════════════════════════════════════════════════════════════
    public function byProduct(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        // Historical revenue report — grouped by product_id (stable identity)
        // ONLY, never also by the live products.name/sku (grouping by both
        // would silently split one product's totals into two rows if it was
        // renamed/re-SKU'd mid-period). Display name/sku come from each row's
        // own frozen invoice_items.product_name/product_sku snapshot.
        $base = InvoiceItem::join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to]);

        if ($request->filled('shop_id')) {
            $base->where('invoices.shop_id', (int) $request->get('shop_id'));
        }

        $rows = (clone $base)
            ->selectRaw('
                invoice_items.product_id,
                COALESCE(SUM(invoice_items.quantity), 0) as total_qty,
                COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue,
                COALESCE(AVG(invoice_items.price), 0) as avg_price,
                COALESCE(MAX(invoice_items.price), 0) as max_price,
                COALESCE(MIN(invoice_items.price), 0) as min_price,
                COUNT(DISTINCT invoices.id) as invoice_count,
                COUNT(DISTINCT invoices.shop_id) as shop_count
            ')
            ->groupBy('invoice_items.product_id')
            ->orderByDesc('total_revenue')
            ->limit(50)
            ->get();

        $productIds = $rows->pluck('product_id');

        // Most recent name/sku snapshot within this same period/filter for
        // each product — what it was actually called during these sales.
        $latestSnapshots = (clone $base)
            ->whereIn('invoice_items.product_id', $productIds)
            ->orderByDesc('invoices.date')
            ->orderByDesc('invoice_items.id')
            ->get(['invoice_items.product_id', 'invoice_items.product_name', 'invoice_items.product_sku'])
            ->unique('product_id')
            ->keyBy('product_id');

        // Unit + category are not identity fields (not in scope for the
        // snapshot) — current values are fine here, purely descriptive.
        $products = \App\Models\Product::with('category:id,name')
            ->whereIn('id', $productIds)
            ->get(['id', 'scalar', 'category_id'])
            ->keyBy('id');

        $rows = $rows->map(function ($r) use ($latestSnapshots, $products) {
            $snapshot = $latestSnapshots[$r->product_id] ?? null;
            $product  = $products[$r->product_id] ?? null;

            return [
                'product_id'    => (int)   $r->product_id,
                'product_name'  => $snapshot->product_name ?? '—',
                'sku'           => $snapshot->product_sku ?? '—',
                'scalar'        => $product?->scalar ?? '',
                'category_name' => $product?->category?->name ?? '—',
                'total_qty'     => round((float) $r->total_qty, 3),
                'total_revenue' => round((float) $r->total_revenue, 2),
                'avg_price'     => round((float) $r->avg_price, 2),
                'max_price'     => round((float) $r->max_price, 2),
                'min_price'     => round((float) $r->min_price, 2),
                'invoice_count' => (int)   $r->invoice_count,
                'shop_count'    => (int)   $r->shop_count,
            ];
        });

        $totalRevenue = $rows->sum('total_revenue');
        $rows = $rows->map(fn ($r) => array_merge($r, [
            'share' => $totalRevenue > 0 ? round(($r['total_revenue'] / $totalRevenue) * 100, 1) : 0,
        ]));

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/by-category
    // Revenue + qty by product category
    // ════════════════════════════════════════════════════════════════
    public function byCategory(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = InvoiceItem::join('invoices',   'invoice_items.invoice_id',  '=', 'invoices.id')
            ->join('products',   'invoice_items.product_id',  '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to]);

        if ($request->filled('shop_id')) {
            $q->where('invoices.shop_id', (int) $request->get('shop_id'));
        }

        $rows = $q->selectRaw("
                products.category_id,
                COALESCE(categories.name, 'بدون فئة') as category_name,
                COUNT(DISTINCT products.id)  as product_count,
                COUNT(DISTINCT invoices.id)  as invoice_count,
                COALESCE(SUM(invoice_items.quantity), 0) as total_qty,
                COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue
            ")
            ->groupBy('products.category_id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($r) => [
                'category_id'   => $r->category_id,
                'category_name' => $r->category_name,
                'product_count' => (int)   $r->product_count,
                'invoice_count' => (int)   $r->invoice_count,
                'total_qty'     => round((float) $r->total_qty, 3),
                'total_revenue' => round((float) $r->total_revenue, 2),
            ]);

        $totalRevenue = $rows->sum('total_revenue');
        $rows = $rows->map(fn ($r) => array_merge($r, [
            'share' => $totalRevenue > 0 ? round(($r['total_revenue'] / $totalRevenue) * 100, 1) : 0,
        ]));

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/customers
    // Customer analysis: registered vs walk-in + top spenders
    // ════════════════════════════════════════════════════════════════
    public function customers(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $base = Invoice::where('status', 'approved')->whereBetween('date', [$from, $to]);
        $this->maybeShop($request, $base);

        $totalInvoices     = (clone $base)->count();
        $registeredInvoices= (clone $base)->whereNotNull('customer_id')->count();
        $walkInInvoices    = (clone $base)->whereNull('customer_id')->count();
        $uniqueRegistered  = (clone $base)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id');
        $registeredRevenue = (clone $base)->whereNotNull('customer_id')->sum('total_amount');
        $walkInRevenue     = (clone $base)->whereNull('customer_id')->sum('total_amount');

        // Top customers
        $topCustomers = (clone $base)
            ->whereNotNull('customer_id')
            ->join('customers', 'invoices.customer_id', '=', 'customers.id')
            ->selectRaw('
                invoices.customer_id,
                customers.name  as customer_name,
                customers.phone as customer_phone,
                COUNT(*)        as visit_count,
                COALESCE(SUM(invoices.total_amount),  0) as total_spent,
                COALESCE(AVG(invoices.total_amount),  0) as avg_spent,
                COALESCE(MAX(invoices.total_amount),  0) as max_invoice,
                COALESCE(MIN(invoices.total_amount),  0) as min_invoice
            ')
            ->groupBy('invoices.customer_id', 'customers.name', 'customers.phone')
            ->orderByDesc('total_spent')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'customer_id'    => (int)   $r->customer_id,
                'customer_name'  => $r->customer_name,
                'customer_phone' => $r->customer_phone,
                'visit_count'    => (int)   $r->visit_count,
                'total_spent'    => round((float) $r->total_spent,   2),
                'avg_spent'      => round((float) $r->avg_spent,     2),
                'max_invoice'    => round((float) $r->max_invoice,   2),
                'min_invoice'    => round((float) $r->min_invoice,   2),
            ]);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period'              => ['from' => $from, 'to' => $to],
                'total_invoices'      => (int) $totalInvoices,
                'registered_invoices' => (int) $registeredInvoices,
                'walk_in_invoices'    => (int) $walkInInvoices,
                'unique_registered'   => (int) $uniqueRegistered,
                'registered_revenue'  => round((float) $registeredRevenue, 2),
                'walk_in_revenue'     => round((float) $walkInRevenue, 2),
                'top_customers'       => $topCustomers,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/hourly
    // Invoice count + revenue by hour of day (0–23)
    // ════════════════════════════════════════════════════════════════
    public function hourly(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = Invoice::where('status', 'approved')->whereBetween('date', [$from, $to]);
        $this->maybeShop($request, $q);

        $rows = $q->selectRaw("
                HOUR(created_at) as hour,
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as revenue
            ")
            ->groupByRaw("HOUR(created_at)")
            ->orderByRaw("HOUR(created_at)")
            ->get()
            ->keyBy('hour');

        // Fill all 24 hours so the chart is complete
        $full = collect(range(0, 23))->map(fn ($h) => [
            'hour'          => $h,
            'invoice_count' => (int)   ($rows->get($h)?->invoice_count ?? 0),
            'revenue'       => round((float) ($rows->get($h)?->revenue ?? 0), 2),
        ]);

        return response()->json(['message' => 'ok', 'data' => $full]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/sales/invoices
    // Paginated invoice list with full filters
    // ════════════════════════════════════════════════════════════════
    public function invoices(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = Invoice::with(['shop:id,name', 'seller:id,name', 'customer:id,name,phone'])
            ->withCount('items')
            ->whereBetween('date', [$from, $to]);

        // Filters
        if ($request->filled('shop_id'))    { $q->where('shop_id',    (int)   $request->get('shop_id')); }
        if ($request->filled('seller_id'))  { $q->where('seller_id',  (int)   $request->get('seller_id')); }
        if ($request->filled('status'))     { $q->where('status',              $request->get('status')); }
        if ($request->filled('min_amount')) { $q->where('total_amount', '>=', (float) $request->get('min_amount')); }
        if ($request->filled('max_amount')) { $q->where('total_amount', '<=', (float) $request->get('max_amount')); }
        if ($request->filled('customer')) {
            $term = $request->get('customer');
            $q->where(function ($sq) use ($term) {
                $sq->whereHas('customer', fn ($cq) =>
                    $cq->where('name',  'like', "%{$term}%")
                       ->orWhere('phone', 'like', "%{$term}%")
                );
            });
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $result  = $q->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        $result->getCollection()->transform(fn ($inv) => [
            'id'             => $inv->id,
            'date'           => $inv->date?->toDateString(),
            'shop_id'        => $inv->shop_id,
            'shop_name'      => $inv->shop?->name   ?? '—',
            'seller_id'      => $inv->seller_id,
            'seller_name'    => $inv->seller?->name ?? '—',
            'customer_id'    => $inv->customer_id,
            'customer_name'  => $inv->customer?->name  ?? 'زبون عابر',
            'customer_phone' => $inv->customer?->phone ?? '—',
            'items_count'    => $inv->items_count,
            'total_amount'   => round((float) $inv->total_amount, 2),
            'status'         => $inv->status,
            'price_type'     => $inv->price_type,
        ]);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }
}
