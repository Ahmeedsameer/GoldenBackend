<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStockIntelligenceController extends Controller
{
    private const LOW_STOCK_DEFAULT = 5;

    // ── Shop-id helper ───────────────────────────────────────────────
    // shop_id absent  → all locations
    // shop_id = 0     → main warehouse (NULL shop_id)
    // shop_id = N > 0 → specific shop
    private function applyLocationFilter(Request $request, $query, string $col = 'g.shop_id')
    {
        if ($request->has('shop_id')) {
            $sid = (int) $request->get('shop_id');
            if ($sid === 0) {
                $query->whereNull($col);
            } else {
                $query->where($col, $sid);
            }
        }
        return $query;
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/stock-intelligence/overview
    // System-wide KPIs + per-location summary
    // ════════════════════════════════════════════════════════════════
    public function overview(Request $request)
    {
        $threshold = max(1, (int) $request->get('threshold', self::LOW_STOCK_DEFAULT));

        // Aggregate (product × location) totals in one pass
        $combos = DB::table('goods as g')
            ->join('supply_items as si', 'g.supply_item_id', '=', 'si.id')
            ->selectRaw('si.product_id, g.shop_id, SUM(g.current_quantity) as loc_qty, SUM(g.current_quantity * si.unit_price) as loc_value')
            ->groupBy('si.product_id', 'g.shop_id')
            ->get();

        $active   = $combos->where('loc_qty', '>', 0);
        $depleted = $combos->where('loc_qty', '<=', 0);

        $shopNames = \App\Models\Shop::pluck('name', 'id');

        $byLocation = $combos->groupBy('shop_id')
            ->map(function ($rows, $shopId) use ($threshold, $shopNames) {
                $activeRows = $rows->where('loc_qty', '>', 0);
                return [
                    'shop_id'         => $shopId,
                    'location_name'   => $shopId ? ($shopNames[$shopId] ?? 'فرع ' . $shopId) : 'المستودع الرئيسي',
                    'sku_count'       => $activeRows->pluck('product_id')->unique()->count(),
                    'stock_value'     => round((float) $activeRows->sum('loc_value'), 2),
                    'low_stock_count' => $activeRows->where('loc_qty', '<=', $threshold)->count(),
                    'out_stock_count' => $rows->where('loc_qty', '<=', 0)->count(),
                ];
            })
            ->sortByDesc('stock_value')
            ->values();

        return response()->json([
            'message' => 'ok',
            'data' => [
                'threshold'           => $threshold,
                'products_with_stock' => $active->pluck('product_id')->unique()->count(),
                'total_stock_value'   => round((float) $active->sum('loc_value'), 2),
                'low_stock_count'     => $active->where('loc_qty', '<=', $threshold)->count(),
                'out_of_stock_count'  => $depleted->count(),
                'active_locations'    => $active->pluck('shop_id')->unique()->count(),
                'warehouse_value'     => round((float) $active->whereNull('shop_id')->sum('loc_value'), 2),
                'by_location'         => $byLocation,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/stock-intelligence/inventory
    // Paginated (product × location) cross-shop inventory
    // Filters: shop_id (0=warehouse), search, category_id
    // ════════════════════════════════════════════════════════════════
    public function inventory(Request $request)
    {
        $q = DB::table('goods as g')
            ->join('supply_items as si', 'g.supply_item_id', '=', 'si.id')
            ->join('products as p',      'si.product_id',    '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id',   '=', 'c.id')
            ->leftJoin('shops as s',      'g.shop_id',       '=', 's.id')
            ->selectRaw("
                si.product_id,
                p.name  as product_name,
                p.sku,
                p.scalar,
                COALESCE(c.name, '—') as category_name,
                g.shop_id,
                COALESCE(s.name, 'المستودع الرئيسي') as location_name,
                COUNT(g.id)                               as batch_count,
                SUM(g.current_quantity)                   as total_qty,
                AVG(si.unit_price)                        as avg_unit_price,
                SUM(g.current_quantity * si.unit_price)   as stock_value
            ")
            ->groupBy('si.product_id', 'p.name', 'p.sku', 'p.scalar', 'c.name', 'g.shop_id', 's.name')
            ->having('total_qty', '>', 0)
            ->orderByDesc('stock_value');

        $this->applyLocationFilter($request, $q);

        if ($request->filled('category_id')) {
            $q->where('p.category_id', (int) $request->get('category_id'));
        }

        if ($request->filled('search')) {
            $s = $request->get('search');
            $q->where(fn ($sq) => $sq->where('p.name', 'like', "%{$s}%")->orWhere('p.sku', 'like', "%{$s}%"));
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $result  = $q->paginate($perPage);

        $result->getCollection()->transform(fn ($r) => [
            'product_id'    => (int)   $r->product_id,
            'product_name'  => $r->product_name,
            'sku'           => $r->sku        ?? '—',
            'scalar'        => $r->scalar     ?? '',
            'category_name' => $r->category_name,
            'shop_id'       => $r->shop_id,
            'location_name' => $r->location_name,
            'batch_count'   => (int)   $r->batch_count,
            'total_qty'     => round((float) $r->total_qty,       3),
            'avg_unit_price'=> round((float) $r->avg_unit_price,  2),
            'stock_value'   => round((float) $r->stock_value,     2),
        ]);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/stock-intelligence/low-stock
    // (product × location) rows at or below threshold
    // ════════════════════════════════════════════════════════════════
    public function lowStock(Request $request)
    {
        $threshold = max(1, (int) $request->get('threshold', self::LOW_STOCK_DEFAULT));

        $q = DB::table('goods as g')
            ->join('supply_items as si', 'g.supply_item_id', '=', 'si.id')
            ->join('products as p',      'si.product_id',    '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id',   '=', 'c.id')
            ->leftJoin('shops as s',      'g.shop_id',       '=', 's.id')
            ->selectRaw("
                si.product_id,
                p.name  as product_name,
                p.sku,
                p.scalar,
                COALESCE(c.name, '—') as category_name,
                g.shop_id,
                COALESCE(s.name, 'المستودع الرئيسي') as location_name,
                SUM(g.current_quantity) as total_qty,
                AVG(si.unit_price)      as avg_unit_price
            ")
            ->groupBy('si.product_id', 'p.name', 'p.sku', 'p.scalar', 'c.name', 'g.shop_id', 's.name')
            ->having('total_qty', '>', 0)
            ->having('total_qty', '<=', $threshold)
            ->orderBy('total_qty', 'asc');

        $this->applyLocationFilter($request, $q);

        $rows = $q->get()->map(fn ($r) => [
            'product_id'    => (int)   $r->product_id,
            'product_name'  => $r->product_name,
            'sku'           => $r->sku    ?? '—',
            'scalar'        => $r->scalar ?? '',
            'category_name' => $r->category_name,
            'shop_id'       => $r->shop_id,
            'location_name' => $r->location_name,
            'total_qty'     => round((float) $r->total_qty,      3),
            'avg_unit_price'=> round((float) $r->avg_unit_price, 2),
        ]);

        return response()->json(['message' => 'ok', 'data' => $rows, 'threshold' => $threshold]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/stock-intelligence/supplies
    // Paginated supply history: per-supply summary with supplier info
    // Filters: supplier_id, from, to
    // ════════════════════════════════════════════════════════════════
    public function supplies(Request $request)
    {
        $q = DB::table('supplies as sup')
            ->join('suppliers as supp', 'sup.supplier_id',  '=', 'supp.id')
            ->join('supply_items as si', 'si.supply_id',    '=', 'sup.id')
            ->selectRaw("
                sup.id,
                sup.date,
                sup.payment_method,
                supp.id   as supplier_id,
                supp.name as supplier_name,
                COUNT(DISTINCT si.product_id) as product_count,
                SUM(si.quantity)              as total_qty,
                SUM(si.quantity * si.unit_price) as total_value
            ")
            ->groupBy('sup.id', 'sup.date', 'sup.payment_method', 'supp.id', 'supp.name')
            ->orderByDesc('sup.date')
            ->orderByDesc('sup.id');

        if ($request->filled('supplier_id')) {
            $q->where('sup.supplier_id', (int) $request->get('supplier_id'));
        }
        if ($request->filled('from')) { $q->where('sup.date', '>=', $request->get('from')); }
        if ($request->filled('to'))   { $q->where('sup.date', '<=', $request->get('to'));   }
        if ($request->filled('search')) {
            $q->where('supp.name', 'like', '%' . $request->get('search') . '%');
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $result  = $q->paginate($perPage);

        $result->getCollection()->transform(fn ($r) => [
            'id'             => $r->id,
            'date'           => (string) $r->date,
            'supplier_id'    => (int)    $r->supplier_id,
            'supplier_name'  => $r->supplier_name,
            'payment_method' => $r->payment_method,
            'product_count'  => (int)    $r->product_count,
            'total_qty'      => round((float) $r->total_qty,   3),
            'total_value'    => round((float) $r->total_value, 2),
        ]);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }
}
