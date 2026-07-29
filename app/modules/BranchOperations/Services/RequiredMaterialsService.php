<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\SupplyItem;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\TransferRequestItem;
use App\Modules\Pricing\Services\PricingService;
use App\Services\WarehouseResolver;

/**
 * Branch Required Materials — a read-only assistant, NOT a new inventory
 * system. Every figure here is reused directly from what already exists:
 *  - stock quantities: the same Goods::sum('current_quantity') pattern used
 *    by InventoryAlertService / ProductDetailService / AdminStockIntelligenceController,
 *    just batched into one grouped query instead of per-product calls.
 *  - "minimum quantity": Product.warning_quantity (already inherited from the
 *    category's default_warning_quantity — see ProductService::applyCategoryThresholdDefaults()),
 *    the exact threshold InventoryAlertService::levelFor() already alerts on.
 *  - pricing completeness: PricingService::rowFor() — the SAME method the
 *    Pricing Management table itself calls, so "needs pricing" here can never
 *    disagree with what Pricing Management shows.
 *  - last purchase price: the same SupplyItem-ordered-by-date pattern
 *    ProductDetailService::inventorySummary() already uses.
 *  - "already requested": TransferRequest/TransferRequestItem, exactly the
 *    engine Transfer Requests / Stock Requests already write to.
 */
class RequiredMaterialsService
{
    /** Non-terminal statuses — a request in any of these still counts as "already requested". */
    private const OPEN_STATUSES = [
        TransferRequest::STATUS_DRAFT,
        TransferRequest::STATUS_SUBMITTED,
        TransferRequest::STATUS_APPROVED,
        TransferRequest::STATUS_PREPARING,
        TransferRequest::STATUS_SHIPPED,
    ];

    public function __construct(private PricingService $pricingService, private WarehouseResolver $warehouse) {}

    public function forBranch(int $shopId): array
    {
        $warehouseShopId = $this->warehouse->warehouseShopId();

        $products = Product::query()
            ->where('is_active', true)
            ->where('product_type', '!=', Product::TYPE_COMPOUND)
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id', 'scalar', 'product_type', 'selling_price', 'price_per_gram', 'default_selling_price', 'priced_cost', 'priced_at', 'warning_quantity', 'critical_quantity']);

        if ($products->isEmpty()) {
            return $this->emptyResult();
        }

        $productIds = $products->pluck('id')->all();

        // One grouped query for both branch and warehouse quantities — never per-product.
        $goodsRows = Goods::query()
            ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
            ->whereIn('supply_items.product_id', $productIds)
            ->where(function ($q) use ($shopId) {
                $q->where('goods.shop_id', $shopId)->orWhereNull('goods.shop_id');
            })
            ->selectRaw('supply_items.product_id as product_id, goods.shop_id as shop_id, SUM(goods.current_quantity) as qty')
            ->groupBy('supply_items.product_id', 'goods.shop_id')
            ->get();

        $branchQty = [];
        $warehouseQty = [];
        foreach ($goodsRows as $row) {
            if ($row->shop_id === null) {
                $warehouseQty[$row->product_id] = (float) $row->qty;
            } elseif ((int) $row->shop_id === $shopId) {
                $branchQty[$row->product_id] = (float) $row->qty;
            }
        }

        // One grouped query for "already requested" — most recent open request per product, if any.
        $openRequests = TransferRequestItem::query()
            ->join('transfer_requests', 'transfer_requests.id', '=', 'transfer_request_items.transfer_request_id')
            ->where('transfer_requests.destination_shop_id', $shopId)
            ->where('transfer_requests.source_shop_id', $warehouseShopId)
            ->whereNull('transfer_requests.cancelled_at')
            ->whereIn('transfer_requests.status', self::OPEN_STATUSES)
            ->whereIn('transfer_request_items.product_id', $productIds)
            ->orderByDesc('transfer_requests.id')
            ->get(['transfer_request_items.product_id', 'transfer_requests.id as request_id', 'transfer_requests.status'])
            ->keyBy('product_id');

        $groups = ['out_of_stock' => [], 'low_stock' => [], 'needs_pricing' => []];

        foreach ($products as $product) {
            $qty = (float) ($branchQty[$product->id] ?? 0);
            $warn = $product->warning_quantity !== null ? (float) $product->warning_quantity : null;

            $stockStatus = null;
            if ($qty <= 0) {
                $stockStatus = 'out_of_stock';
            } elseif ($warn !== null && $qty <= $warn) {
                $stockStatus = 'low_stock';
            }

            $pricingRow = $this->pricingService->rowFor($product);
            $isPriced = $pricingRow['status'] !== 'no_price';

            // A product's status group: its stock problem takes precedence; a
            // pricing gap only creates its own row when stock is otherwise fine
            // (an out-of-stock/low-stock-but-unpriced product still shows in its
            // stock group — pricing only disables that row's request button,
            // per the "Pricing Rule": it gates requesting everywhere, not just
            // the Needs Pricing group).
            $group = $stockStatus ?? ($isPriced ? null : 'needs_pricing');
            if ($group === null) {
                continue;
            }

            $existing = $openRequests->get($product->id);

            $groups[$group][] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->name,
                'branch_qty' => round($qty, 3),
                'warehouse_qty' => round((float) ($warehouseQty[$product->id] ?? 0), 3),
                'minimum_quantity' => $warn,
                'last_purchase_price' => $this->lastPurchasePrice($product->id),
                'is_priced' => $isPriced,
                'can_request' => $isPriced && ! $existing,
                'existing_request' => $existing ? [
                    'id' => $existing->request_id,
                    'status' => $existing->status,
                    'status_label' => self::STATUS_LABELS[$existing->status] ?? $existing->status,
                ] : null,
            ];
        }

        foreach ($groups as &$rows) {
            usort($rows, fn ($a, $b) => $a['branch_qty'] <=> $b['branch_qty']);
        }
        unset($rows);

        $pendingRequests = collect($groups['out_of_stock'])->merge($groups['low_stock'])
            ->filter(fn ($r) => $r['existing_request'] !== null)->count();

        return [
            'summary' => [
                'out_of_stock' => count($groups['out_of_stock']),
                'low_stock' => count($groups['low_stock']),
                'needs_pricing' => count($groups['needs_pricing']),
                'pending_requests' => $pendingRequests,
            ],
            'out_of_stock' => $groups['out_of_stock'],
            'low_stock' => $groups['low_stock'],
            'needs_pricing' => $groups['needs_pricing'],
        ];
    }

    private const STATUS_LABELS = [
        'draft' => 'مسودة', 'submitted' => 'بانتظار الموافقة', 'approved' => 'تمت الموافقة',
        'preparing' => 'قيد التجهيز', 'shipped' => 'تم الشحن',
    ];

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

    private function emptyResult(): array
    {
        return [
            'summary' => ['out_of_stock' => 0, 'low_stock' => 0, 'needs_pricing' => 0, 'pending_requests' => 0],
            'out_of_stock' => [], 'low_stock' => [], 'needs_pricing' => [],
        ];
    }
}
