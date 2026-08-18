<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SupplyItem;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\TransferRequestItem;
use App\Modules\Pricing\Services\PricingService;
use App\Services\WarehouseResolver;

/**
 * Branch Required Materials (manager, one branch) AND Global Branch Material
 * Status (admin, all branches) — ONE computation engine, three presentations.
 * Never a second inventory system. Every figure is reused directly:
 *  - stock quantities: the same Goods::sum('current_quantity') pattern used
 *    by InventoryAlertService / ProductDetailService / AdminStockIntelligenceController,
 *    just batched into one grouped query instead of per-product calls.
 *  - "minimum quantity": Product.warning_quantity (inherited from the
 *    category's default_warning_quantity — see ProductService::applyCategoryThresholdDefaults()),
 *    the exact threshold InventoryAlertService::levelFor() already alerts on.
 *  - pricing completeness: PricingService::rowFor() — the SAME method the
 *    Pricing Management table itself calls, so "needs pricing" here can never
 *    disagree with what Pricing Management shows.
 *  - last purchase price / supplier: the same SupplyItem-ordered-by-date
 *    pattern ProductDetailService::inventorySummary()/supplierHistory() use.
 *  - "already requested" / "in transit" / "delivered": TransferRequest and
 *    TransferRequestItem, exactly the engine Transfer Requests / Stock
 *    Requests already write to — no new request/status model.
 */
class RequiredMaterialsService
{
    /** Non-terminal statuses — a request in any of these still counts as "already requested" / "in transit". */
    private const OPEN_STATUSES = [
        TransferRequest::STATUS_DRAFT,
        TransferRequest::STATUS_SUBMITTED,
        TransferRequest::STATUS_APPROVED,
        TransferRequest::STATUS_PREPARING,
        TransferRequest::STATUS_SHIPPED,
    ];

    private const IN_TRANSIT_STATUSES = [
        TransferRequest::STATUS_APPROVED,
        TransferRequest::STATUS_PREPARING,
        TransferRequest::STATUS_SHIPPED,
    ];

    /** A documented, honest heuristic — not derived from any historical baseline
     *  (no request-volume history exists in this ERP to compute a "normal" rate from). */
    private const PENDING_ALERT_THRESHOLD = 5;

    private const STATUS_LABELS = [
        'draft' => 'مسودة', 'submitted' => 'بانتظار الموافقة', 'approved' => 'تمت الموافقة',
        'preparing' => 'قيد التجهيز', 'shipped' => 'تم الشحن',
    ];

    public function __construct(private PricingService $pricingService, private WarehouseResolver $warehouse) {}

    // ── Manager: one branch (existing page, unchanged output shape) ───────────

    public function forBranch(int $shopId): array
    {
        $rows = $this->computeRows([$shopId]);
        $bucketed = $this->bucketByStatus($rows[$shopId] ?? []);

        $pendingRequests = collect($bucketed['out_of_stock'])->merge($bucketed['low_stock'])
            ->filter(fn ($r) => $r['existing_request'] !== null)->count();

        return [
            'summary' => [
                'out_of_stock' => count($bucketed['out_of_stock']),
                'low_stock' => count($bucketed['low_stock']),
                'needs_pricing' => count($bucketed['needs_pricing']),
                'pending_requests' => $pendingRequests,
            ],
            'out_of_stock' => $bucketed['out_of_stock'],
            'low_stock' => $bucketed['low_stock'],
            'needs_pricing' => $bucketed['needs_pricing'],
        ];
    }

    // ── Admin: every branch, one card each (Global Branch Material Status — By Branch) ──

    public function forAllBranches(): array
    {
        $warehouseShopId = $this->warehouse->warehouseShopId();
        $shops = Shop::where('status', 'active')->where('is_warehouse', false)->get(['id', 'name']);
        $shopIds = $shops->pluck('id')->all();

        $rows = $this->computeRows($shopIds);

        // received_today / in_transit — counted once per branch, not per product row.
        $today = now()->toDateString();
        $receivedToday = TransferRequestItem::query()
            ->join('transfer_requests', 'transfer_requests.id', '=', 'transfer_request_items.transfer_request_id')
            ->where('transfer_requests.source_shop_id', $warehouseShopId)
            ->whereIn('transfer_requests.destination_shop_id', $shopIds)
            ->whereIn('transfer_requests.status', [TransferRequest::STATUS_RECEIVED, TransferRequest::STATUS_CLOSED])
            ->where(function ($q) use ($today) {
                $q->whereDate('transfer_requests.received_at', $today)->orWhereDate('transfer_requests.closed_at', $today);
            })
            ->selectRaw('transfer_requests.destination_shop_id as shop_id, COUNT(DISTINCT transfer_request_items.id) as cnt')
            ->groupBy('transfer_requests.destination_shop_id')
            ->pluck('cnt', 'shop_id');

        $inTransit = TransferRequestItem::query()
            ->join('transfer_requests', 'transfer_requests.id', '=', 'transfer_request_items.transfer_request_id')
            ->where('transfer_requests.source_shop_id', $warehouseShopId)
            ->whereIn('transfer_requests.destination_shop_id', $shopIds)
            ->whereIn('transfer_requests.status', self::IN_TRANSIT_STATUSES)
            ->selectRaw('transfer_requests.destination_shop_id as shop_id, COUNT(DISTINCT transfer_request_items.id) as cnt')
            ->groupBy('transfer_requests.destination_shop_id')
            ->pluck('cnt', 'shop_id');

        $branches = [];
        $alerts = ['never_requested_zero_stock' => [], 'priced_but_ignored' => [], 'too_many_pending' => []];

        foreach ($shops as $shop) {
            $bucketed = $this->bucketByStatus($rows[$shop->id] ?? []);
            $pendingRequests = collect($bucketed['out_of_stock'])->merge($bucketed['low_stock'])
                ->filter(fn ($r) => $r['existing_request'] !== null)->count();

            $branches[] = [
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
                'summary' => [
                    'out_of_stock' => count($bucketed['out_of_stock']),
                    'low_stock' => count($bucketed['low_stock']),
                    'needs_pricing' => count($bucketed['needs_pricing']),
                    'pending_requests' => $pendingRequests,
                    'received_today' => (int) ($receivedToday[$shop->id] ?? 0),
                    'in_transit' => (int) ($inTransit[$shop->id] ?? 0),
                ],
                'out_of_stock' => $bucketed['out_of_stock'],
                'low_stock' => $bucketed['low_stock'],
                'needs_pricing' => $bucketed['needs_pricing'],
            ];

            foreach (($rows[$shop->id] ?? []) as $row) {
                if ($row['stock_status'] !== 'available' && $row['never_requested']) {
                    $entry = ['shop_id' => $shop->id, 'shop_name' => $shop->name, 'product_id' => $row['product_id'], 'name' => $row['name']];
                    $alerts['never_requested_zero_stock'][] = $entry;
                    if ($row['is_priced']) {
                        $alerts['priced_but_ignored'][] = $entry;
                    }
                }
            }
            if ($pendingRequests >= self::PENDING_ALERT_THRESHOLD) {
                $alerts['too_many_pending'][] = ['shop_id' => $shop->id, 'shop_name' => $shop->name, 'count' => $pendingRequests];
            }
        }

        $needsPricingTotal = collect($rows)->flatten(1)->where('stock_status', 'available')->where('is_priced', false)->unique('product_id')->count();

        return [
            'summary' => [
                'branches_with_shortages' => collect($branches)->filter(fn ($b) => $b['summary']['out_of_stock'] > 0 || $b['summary']['low_stock'] > 0)->count(),
                'total_missing_materials' => collect($branches)->sum(fn ($b) => $b['summary']['out_of_stock']),
                'pending_requests' => collect($branches)->sum(fn ($b) => $b['summary']['pending_requests']),
                'delivered_today' => collect($branches)->sum(fn ($b) => $b['summary']['received_today']),
                'materials_awaiting_pricing' => $needsPricingTotal,
                'branches_needing_attention' => count($alerts['too_many_pending']),
            ],
            'branches' => $branches,
            'alerts' => $alerts,
        ];
    }

    // ── Admin: cross-branch, one row per material (Global Branch Material Status — By Material) ──

    public function byMaterial(): array
    {
        $shops = Shop::where('status', 'active')->where('is_warehouse', false)->get(['id', 'name'])->keyBy('id');
        $shopIds = $shops->keys()->all();

        $rows = $this->computeRows($shopIds);

        // Regroup by product — only products with a problem in at least one branch (matches the
        // page's purpose: rebalancing what's wrong, not re-listing the entire healthy catalog).
        $byProduct = [];
        foreach ($rows as $shopId => $shopRows) {
            foreach ($shopRows as $row) {
                $byProduct[$row['product_id']]['product'] = $row;
                $byProduct[$row['product_id']]['branches'][] = [
                    'shop_id' => $shopId,
                    'shop_name' => $shops[$shopId]->name,
                    'qty' => $row['branch_qty'],
                    'status' => $row['stock_status'],
                    'minimum_quantity' => $row['minimum_quantity'],
                ];
            }
        }

        $data = [];
        foreach ($byProduct as $productId => $entry) {
            $branches = $entry['branches'];
            $hasProblem = collect($branches)->contains(fn ($b) => $b['status'] !== 'available') || ! $entry['product']['is_priced'];
            if (! $hasProblem) {
                continue;
            }

            // Surplus = a branch comfortably above its own reorder point (not itself short) — largest qty wins.
            $surplus = collect($branches)
                ->filter(fn ($b) => $b['status'] === 'available' && $b['minimum_quantity'] !== null && $b['qty'] > $b['minimum_quantity'])
                ->sortByDesc('qty')->first();

            $supplier = $this->lastSupplier($productId);

            $data[] = [
                'product_id' => $productId,
                'name' => $entry['product']['name'],
                'category' => $entry['product']['category'],
                'is_priced' => $entry['product']['is_priced'],
                'last_purchase_price' => $entry['product']['last_purchase_price'],
                'supplier' => $supplier,
                'branches' => $branches,
                'surplus_branch' => $surplus ? ['shop_id' => $surplus['shop_id'], 'shop_name' => $surplus['shop_name'], 'qty' => $surplus['qty']] : null,
            ];
        }

        return ['rows' => $data];
    }

    // ── Shared computation — the ONE place stock/pricing/request data is joined ──

    /** @return array<int, array<int, array>> keyed by shop_id, each a list of per-product rows for that shop. */
    private function computeRows(array $shopIds): array
    {
        if (empty($shopIds)) {
            return [];
        }

        $warehouseShopId = $this->warehouse->warehouseShopId();

        $products = Product::query()
            ->where('is_active', true)
            ->notArchived()
            ->where('product_type', '!=', Product::TYPE_COMPOUND)
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id', 'scalar', 'product_type', 'selling_price', 'price_per_gram', 'default_selling_price', 'priced_cost', 'priced_at', 'warning_quantity', 'critical_quantity']);

        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id')->all();

        // One grouped query for stock across every requested branch + the warehouse.
        $goodsRows = Goods::query()
            ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
            ->whereIn('supply_items.product_id', $productIds)
            ->where(function ($q) use ($shopIds) {
                $q->whereIn('goods.shop_id', $shopIds)->orWhereNull('goods.shop_id');
            })
            ->selectRaw('supply_items.product_id as product_id, goods.shop_id as shop_id, SUM(goods.current_quantity) as qty')
            ->groupBy('supply_items.product_id', 'goods.shop_id')
            ->get();

        $qtyByShopProduct = [];
        $warehouseQty = [];
        foreach ($goodsRows as $row) {
            if ($row->shop_id === null) {
                $warehouseQty[$row->product_id] = (float) $row->qty;
            } else {
                $qtyByShopProduct[(int) $row->shop_id][$row->product_id] = (float) $row->qty;
            }
        }

        // One grouped query for OPEN requests across every requested branch.
        $openRequests = TransferRequestItem::query()
            ->join('transfer_requests', 'transfer_requests.id', '=', 'transfer_request_items.transfer_request_id')
            ->where('transfer_requests.source_shop_id', $warehouseShopId)
            ->whereIn('transfer_requests.destination_shop_id', $shopIds)
            ->whereNull('transfer_requests.cancelled_at')
            ->whereIn('transfer_requests.status', self::OPEN_STATUSES)
            ->whereIn('transfer_request_items.product_id', $productIds)
            ->orderByDesc('transfer_requests.id')
            ->get(['transfer_request_items.product_id', 'transfer_requests.destination_shop_id', 'transfer_requests.id as request_id', 'transfer_requests.status'])
            ->groupBy('destination_shop_id');

        // One grouped query for "ever requested at all" (any status) — powers "never requested" alerts.
        $everRequested = TransferRequestItem::query()
            ->join('transfer_requests', 'transfer_requests.id', '=', 'transfer_request_items.transfer_request_id')
            ->where('transfer_requests.source_shop_id', $warehouseShopId)
            ->whereIn('transfer_requests.destination_shop_id', $shopIds)
            ->whereIn('transfer_request_items.product_id', $productIds)
            ->selectRaw('transfer_requests.destination_shop_id as shop_id, transfer_request_items.product_id as product_id')
            ->distinct()
            ->get()
            ->groupBy('shop_id');

        $everRequestedSet = [];
        foreach ($everRequested as $shopId => $items) {
            $everRequestedSet[$shopId] = $items->pluck('product_id')->flip();
        }

        $result = [];
        foreach ($shopIds as $shopId) {
            $openForShop = ($openRequests[$shopId] ?? collect())->keyBy('product_id');

            foreach ($products as $product) {
                $qty = (float) ($qtyByShopProduct[$shopId][$product->id] ?? 0);
                $warn = $product->warning_quantity !== null ? (float) $product->warning_quantity : null;

                $stockStatus = 'available';
                if ($qty <= 0) {
                    $stockStatus = 'out_of_stock';
                } elseif ($warn !== null && $qty <= $warn) {
                    $stockStatus = 'low_stock';
                }

                $pricingRow = $this->pricingService->rowFor($product);
                $isPriced = $pricingRow['status'] !== 'no_price';

                $existing = $openForShop->get($product->id);
                $neverRequested = ! isset($everRequestedSet[$shopId]) || ! $everRequestedSet[$shopId]->has($product->id);

                $result[$shopId][] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'branch_qty' => round($qty, 3),
                    'warehouse_qty' => round((float) ($warehouseQty[$product->id] ?? 0), 3),
                    'minimum_quantity' => $warn,
                    'last_purchase_price' => $this->lastPurchasePrice($product->id),
                    'is_priced' => $isPriced,
                    'stock_status' => $stockStatus,
                    'never_requested' => $neverRequested,
                    'can_request' => $isPriced && ! $existing,
                    'existing_request' => $existing ? [
                        'id' => $existing->request_id,
                        'status' => $existing->status,
                        'status_label' => self::STATUS_LABELS[$existing->status] ?? $existing->status,
                    ] : null,
                ];
            }
        }

        return $result;
    }

    /** Groups one shop's computeRows() output into the existing out_of_stock/low_stock/needs_pricing shape. */
    private function bucketByStatus(array $shopRows): array
    {
        $groups = ['out_of_stock' => [], 'low_stock' => [], 'needs_pricing' => []];

        foreach ($shopRows as $row) {
            // Stock problem takes precedence; a pricing gap only creates its own row when
            // stock is otherwise fine — pricing still disables the request button everywhere
            // (see "can_request"), it doesn't duplicate the row into a second group.
            $group = match ($row['stock_status']) {
                'out_of_stock' => 'out_of_stock',
                'low_stock' => 'low_stock',
                default => $row['is_priced'] ? null : 'needs_pricing',
            };
            if ($group === null) {
                continue;
            }

            $r = $row;
            unset($r['stock_status'], $r['never_requested']);
            $groups[$group][] = $r;
        }

        foreach ($groups as &$rows) {
            usort($rows, fn ($a, $b) => $a['branch_qty'] <=> $b['branch_qty']);
        }
        unset($rows);

        return $groups;
    }

    private function lastPurchasePrice(int $productId): ?float
    {
        $price = SupplyItem::query()
            ->join('supplies', 'supplies.id', '=', 'supply_items.supply_id')
            ->where('supply_items.product_id', $productId)
            ->orderByDesc('supplies.date')
            ->orderByDesc('supply_items.id')
            ->value('supply_items.unit_price');

        return $price !== null ? (float) $price : null;
    }

    /** The single most recent supplier for a product — a one-row extension of the same
     *  supply_items→supplies join ProductDetailService::supplierHistory() already uses. */
    private function lastSupplier(int $productId): ?array
    {
        $row = SupplyItem::query()
            ->join('supplies', 'supplies.id', '=', 'supply_items.supply_id')
            ->join('suppliers', 'suppliers.id', '=', 'supplies.supplier_id')
            ->where('supply_items.product_id', $productId)
            ->orderByDesc('supplies.date')
            ->orderByDesc('supply_items.id')
            ->first(['suppliers.id as supplier_id', 'suppliers.name as supplier_name']);

        return $row ? ['id' => $row->supplier_id, 'name' => $row->supplier_name] : null;
    }
}
