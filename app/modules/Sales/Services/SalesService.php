<?php

namespace App\Modules\Sales\Services;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Goods;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\Safe;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Safe\Services\SafeService;
use App\Modules\Hr\Services\ActiveBranchService;
use App\Modules\Stock\Services\InventoryAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesService
{
    public function __construct(
        private SafeService $safeService,
        private InventoryAlertService $inventoryAlerts,
    ) {}
    // ── Frontend utility: search available goods in seller's shop ─────────────

    public function searchGoods(int $shopId, ?string $search, int $perPage, ?int $categoryId = null): mixed
    {
        $query = Goods::query()
            ->with([
                'supplyItem.product:id,name,sku,scalar,category_id,selling_price,price_per_gram',
                'supplyItem.product.category:id,name,minimum_sell_price,price_per_gram,is_fixed,value_percentage,product_type_id',
                'supplyItem.product.category.productType:id,sold_by,pricing_source,default_unit',
            ])
            ->where('shop_id', $shopId);

        if ($search) {
            // Reuse the single Product::scopeSearch matcher (name / sku / barcode)
            // so the cashier search behaves identically to Supply/Transfer.
            $query->whereHas('supplyItem.product', fn ($q) => $q->search($search));
        }

        if ($categoryId) {
            $query->whereHas('supplyItem.product', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        $goods = $query->paginate($perPage);

        // Attach a per-product shop stock total + a traffic-light stock level so
        // the cashier can show green/yellow/red and a lightweight "only N left"
        // warning. Thresholds themselves are NOT exposed (computed server-side),
        // so sales users never see configured management values (Section #13/#17).
        $this->decorateWithStockLevel($goods->getCollection(), $shopId);

        return $goods;
    }

    /** Compute per-product shop stock + level for a collection of Goods rows. */
    private function decorateWithStockLevel(\Illuminate\Support\Collection $rows, int $shopId): void
    {
        $productIds = $rows
            ->map(fn ($g) => optional(optional($g->supplyItem)->product)->id)
            ->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $totals = Goods::query()
            ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
            ->whereIn('supply_items.product_id', $productIds)
            ->where('goods.shop_id', $shopId)
            ->groupBy('supply_items.product_id')
            ->selectRaw('supply_items.product_id as pid, SUM(goods.current_quantity) as total')
            ->pluck('total', 'pid');

        $thresholds = Product::whereIn('id', $productIds)
            ->get(['id', 'warning_quantity', 'critical_quantity'])
            ->keyBy('id');

        foreach ($rows as $g) {
            $product = optional($g->supplyItem)->product;
            $pid   = $product->id ?? null;
            $stock = (float) ($totals[$pid] ?? 0);
            $g->setAttribute('product_shop_stock', $stock);
            $g->setAttribute('stock_level', $this->stockLevel($thresholds[$pid] ?? null, $stock));

            // Pricing metadata so the cashier can auto-compute line totals:
            //   configured_unit_price → oil: category price/gram, non-oil: product
            //   selling_price (null when unconfigured → cashier uses Global Total).
            $type = optional($product?->category)->productType;
            $g->setAttribute('configured_unit_price', $product ? $this->resolveConfiguredUnitPrice($product) : null);
            $g->setAttribute('sells_by', $type->sold_by ?? 'unit');
            $g->setAttribute('pricing_source', $type->pricing_source ?? null);
            // Selling unit is defined by the Product Type (g / pcs), else the product's own scalar.
            $g->setAttribute('unit', $type->default_unit ?? ($product->scalar ?? ''));
        }
    }

    /**
     * Whether a category prices at a fixed value (bottle-like) vs a weighted
     * pool (oil-like). Behavior now lives on the Product Type; we fall back to
     * the category's own legacy is_fixed so nothing breaks for categories that
     * are not yet linked to a type.
     */
    private function categoryIsFixed($category): bool
    {
        if (! $category) {
            return false;
        }
        if ($category->productType) {
            return (bool) $category->productType->is_fixed;
        }
        return (bool) $category->is_fixed;
    }

    /** ok | warning | critical | out — mirrors InventoryAlertService thresholds. */
    private function stockLevel(?Product $product, float $qty): string
    {
        if ($qty <= 0) {
            return 'out';
        }
        $crit = $product?->critical_quantity !== null ? (float) $product->critical_quantity : null;
        $warn = $product?->warning_quantity  !== null ? (float) $product->warning_quantity  : null;

        if ($crit !== null && $qty <= $crit) {
            return 'critical';
        }
        if ($warn !== null && $qty <= $warn) {
            return 'warning';
        }
        return 'ok';
    }

    // ── Frontend utility: catalog products matching the search that are NOT ───
    // stocked in the seller's shop. Used purely to show an informational hint
    // in the cashier ("product exists but not in this branch's stock — supply/
    // transfer it first"). It never makes these products sellable.
    public function searchUnstockedProducts(int $shopId, ?string $search, int $limit = 10): mixed
    {
        if (! $search) {
            return collect();
        }

        return Product::query()
            ->where('is_active', true)
            ->search($search)   // single reusable matcher (name / sku / barcode)
            // Exclude any product that already has an inventory row in this shop
            // (those are returned by searchGoods and are sellable).
            ->whereNotExists(function ($q) use ($shopId) {
                $q->select(DB::raw(1))
                  ->from('goods')
                  ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
                  ->whereColumn('supply_items.product_id', 'products.id')
                  ->where('goods.shop_id', $shopId);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'scalar']);
    }

    // ── Frontend utility: search customers by phone ────────────────────────────

    public function searchCustomers(?string $phone, int $perPage): mixed
    {
        $query = Customer::query();

        if ($phone) {
            $query->where('phone', 'like', "%{$phone}%");
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Frontend utility: search testers in the same shop ─────────────────────

    public function searchTesters(?string $search, int $perPage): mixed
    {
        $query = User::query()
            ->where('role', 'sales')
            ->where('shop_id', auth()->user()->shop_id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name',  'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Core: create invoice with automatic inventory deduction ───────────────

    /**
     * @return array{invoice: Invoice, violations: array}
     */
    public function createInvoice(array $data): array
    {
        $seller = auth()->user();

        if (! $seller->shop_id) {
            abort(422, 'البائع غير مرتبط بأي فرع، يرجى التواصل مع المدير');
        }

        // ── Resolve the seller's ACTIVE branch (primary, or a live transfer) ──
        // Every branch-scoped operation below (stock, invoice branch, FIFO, safe,
        // alerts) uses this. For a non-transferred employee it equals shop_id, so
        // existing behaviour is unchanged (backward compatible).
        $activeShopId   = app(ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;
        $activeBranch   = Shop::find($activeShopId, ['id', 'name']);
        $activeBranchNm = $activeBranch?->name;

        // ── Validate override token (if provided) ────────────────────────────
        $overrideApproved = false;
        if (! empty($data['override_token'])) {
            $tokenKey  = "override_token:{$data['override_token']}";
            $tokenData = Cache::get($tokenKey);

            if (
                $tokenData &&
                (int) $tokenData['seller_id'] === $seller->id &&
                (int) $tokenData['shop_id']   === $activeShopId
            ) {
                $overrideApproved = true;
                Cache::forget($tokenKey); // one-time use
            }
        }

        $result = DB::transaction(function () use ($data, $seller, $overrideApproved, $activeShopId, $activeBranchNm) {
            // ── 1. Find or create customer by phone ──────────────────────────
            $customer = null;
            if (! empty($data['phone']) || ! empty($data['name'])) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $data['phone']],
                    ['name'  => $data['name']]
                );
            }

            // ── 2. Load products with full category data ──────────────────────
            $productIds = array_column($data['items'], 'product_id');
            $products   = Product::with([
                    'category:id,name,minimum_sell_price,price_per_gram,is_fixed,value_percentage,product_type_id',
                    'category.productType:id,is_fixed,sold_by,pricing_source',
                ])
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            // ── 2a. Pricing guard — a Ready Product that has been purchased
            //     must have a selling price configured in Pricing Management
            //     before it can be sold; Purchasing/Supply never sets this.
            //     Compound Products are exempt (price entered fresh every
            //     sale in the Product Builder), as are oil/bottle
            //     sub-component lines (role !== null) whose price comes from
            //     the Builder's own reference cost, not a stored price. ────
            foreach ($data['items'] as $item) {
                if (! empty($item['role'])) {
                    continue;
                }
                $product = $products[$item['product_id']] ?? null;
                if ($product && $product->product_type === Product::TYPE_READY_PRODUCT && $product->selling_price === null) {
                    abort(422, "المنتج \"{$product->name}\" ليس له سعر بيع محدد — يجب إكمال إدارة الأسعار أولاً.");
                }
            }

            // ── 2b. Stock guard — never sell more than what is in this shop ──
            // Sum the requested quantity per product (an item may span rows),
            // then compare against the available shop stock. Out-of-stock and
            // over-sell are both rejected before any deduction. FIFO untouched.
            $requestedByProduct = [];
            foreach ($data['items'] as $item) {
                $pid = (int) $item['product_id'];
                $requestedByProduct[$pid] = ($requestedByProduct[$pid] ?? 0) + (float) $item['quantity'];
            }

            foreach ($requestedByProduct as $pid => $requested) {
                $available = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $pid))
                    ->where('shop_id', $activeShopId)
                    ->sum('current_quantity');

                $name = $products[$pid]->name ?? "#{$pid}";
                $unit = $products[$pid]->scalar ?? '';

                if ($available <= 0) {
                    abort(422, "المنتج \"{$name}\" نفد من المخزون في هذا الفرع ولا يمكن بيعه.");
                }

                if ($requested > $available) {
                    $reqTxt = rtrim(rtrim(number_format($requested, 3, '.', ''), '0'), '.');
                    $availTxt = rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.');
                    abort(422, "الكمية المطلوبة من \"{$name}\" ({$reqTxt} {$unit}) أكبر من المتاح في المخزون ({$availTxt} {$unit}). لا يمكن إتمام البيع.");
                }
            }

            // ── 3. Price each item ───────────────────────────────────────────
            // NEW per-item engine when every item is configured (oil → category
            // price/gram, non-oil → product selling_price); otherwise fall back
            // to the legacy global-total distribution. $effectiveTotal is the
            // authoritative invoice total from here on.
            [$items, $effectiveTotal] = $this->priceInvoiceItems($data, $products);

            // ── 4. Validate payment total against the invoice total (physical safe) ─
            if (! empty($data['payments'])) {
                $currencyIds      = array_unique(array_column($data['payments'], 'currency_id'));
                $rates            = Currency::whereIn('id', $currencyIds)->pluck('rate', 'id');
                $paymentsEgpTotal = 0.0;

                foreach ($data['payments'] as $payment) {
                    $rate              = (float) ($rates[$payment['currency_id']] ?? 0);
                    $paymentsEgpTotal += (float) $payment['amount'] * $rate;
                }

                if ($paymentsEgpTotal < $effectiveTotal) {
                    abort(422, sprintf(
                        'مجموع المبالغ المدفوعة بعد التحويل إلى الجنيه المصري (%.2f ج.م) أقل من إجمالي الفاتورة (%.2f ج.م). يرجى مراجعة المبالغ المُدخلة.',
                        $paymentsEgpTotal,
                        $effectiveTotal
                    ));
                }
            }

            // ── 5. Check computed prices against category minimums ────────────
            $violations = [];
            foreach ($items as $item) {
                $product  = $products[$item['product_id']] ?? null;
                $category = $product?->category;

                // Fixed-price items are always at the minimum — skip them
                if ($category && ! $this->categoryIsFixed($category)
                    && (float) $item['price'] < (float) $category->minimum_sell_price) {
                    $violations[] = [
                        'product_id'         => $product->id,
                        'product_name'       => $product->name,
                        'category_name'      => $category->name,
                        'sell_price'         => (float) $item['price'],
                        'minimum_sell_price' => (float) $category->minimum_sell_price,
                    ];
                }
            }

            // ── 6. Create invoice header ──────────────────────────────────────
            // Status is 'approved' when: no violations, OR manager pre-approved via token.
            $invoice = Invoice::create([
                'customer_id'  => $customer?->id,
                'shop_id'      => $activeShopId,
                'branch_name'  => $activeBranchNm,
                'seller_id'    => $seller->id,
                'seller_name'  => $seller->name,
                'seller_email' => $seller->email,
                'date'         => now()->toDateString(),
                'price_type'   => $data['price_type'],
                'status'       => ($violations && ! $overrideApproved) ? 'pending' : 'approved',
                'total_amount' => $effectiveTotal,
            ]);

            // ── 7. Process each item — FIFO batch splitting + deduction ───────
            foreach ($items as $item) {
                $this->processItem($invoice, $item);
            }

            // ── 8. Resolve the safe ───────────────────────────────────────────
            if (! empty($data['safe_id'])) {
                $safe = Safe::with('safeType')
                    ->where('id', (int) $data['safe_id'])
                    ->where('shop_id', $activeShopId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $safe = Safe::with('safeType')
                    ->where('shop_id', $activeShopId)
                    ->whereHas('safeType', fn($q) => $q->where('kind', 'physical'))
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // ── 9. Record payments ────────────────────────────────────────────
            if ($safe->safeType->kind === 'physical' && ! empty($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    InvoicePayment::create([
                        'invoice_id'         => $invoice->id,
                        'safe_id'            => $safe->id,
                        'currency_id'        => (int) $payment['currency_id'],
                        'amount'             => (float) $payment['amount'],
                        'payment_method'     => $payment['payment_method'] ?? PaymentMethod::Cash->value,
                        'transaction_number' => ! empty($payment['transaction_number'])
                            ? $payment['transaction_number']
                            : null,
                    ]);
                    $this->safeService->recordSaleTransaction(
                        $safe, $invoice, (int) $payment['currency_id'], (float) $payment['amount'], $seller->id
                    );
                }
            } else {
                // Virtual safe → record the invoice total directly in EGP
                $egpCurrencyId = Currency::where('code', 'EGP')->value('id');
                if ($egpCurrencyId) {
                    $this->safeService->recordSaleTransaction(
                        $safe, $invoice, (int) $egpCurrencyId, $effectiveTotal, $seller->id
                    );
                }
            }

            return [
                'invoice'    => $invoice->load([
                    'customer',
                    'seller:id,name',
                    'shop:id,name',
                    'items.product:id,name,sku,scalar',
                    'items.parentProduct:id,name',
                    'items.goods',
                    'payments.currency:id,code,symbol',
                ]),
                'violations' => $violations,
            ];
        });

        // ── Post-commit side effects (stock is final here) ───────────────────
        // 1) Re-evaluate inventory level for each sold product → low/critical/out
        //    notifications to admins + the branch manager (de-duped by state).
        $soldProductIds = array_unique(array_column($data['items'], 'product_id'));
        foreach ($soldProductIds as $pid) {
            $this->inventoryAlerts->evaluate((int) $pid, $activeShopId);
        }

        // 2) Price-violation alert (sold below the category minimum).
        if (! empty($result['violations'])) {
            $this->inventoryAlerts->notifyPriceViolation(
                $activeShopId,
                $result['invoice']->id,
                $result['violations']
            );
        }

        return $result;
    }

    // ── Deduct inventory across FIFO batches, split InvoiceItem per batch ─────

    private function processItem(Invoice $invoice, array $item): void
    {
        $productId       = (int) $item['product_id'];
        $needed          = (float) $item['quantity'];
        $price           = (float) $item['price'];
        // Compose-dialog tagging only — both null for every normal/legacy line.
        $parentProductId = isset($item['parent_product_id']) ? (int) $item['parent_product_id'] : null;
        $role            = $item['role'] ?? null;

        // Fetch all batches with stock for this product in the seller's shop,
        // ordered oldest-first (FIFO) and locked for the transaction.
        $batches = Goods::whereHas('supplyItem', fn($q) => $q->where('product_id', $productId))
            ->where('shop_id', $invoice->shop_id)
            ->where('current_quantity', '>', 0)
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $needed;

        // FIFO: drain available batches
        foreach ($batches as $goods) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $goods->current_quantity, $remaining);

            // Deduct — never delete the row even if it reaches zero
            $goods->decrement('current_quantity', $take);

            InvoiceItem::create([
                'invoice_id'         => $invoice->id,
                'product_id'         => $productId,
                'parent_product_id'  => $parentProductId,
                'role'               => $role,
                'goods_id'           => $goods->id,
                'quantity'           => $take,
                'price'              => $price,
            ]);

            $remaining = round($remaining - $take, 3);
        }

        // Oversell: if demand exceeds available stock, push the remainder into
        // the most-recent batch (driving its quantity negative).  goods_id is
        // non-nullable, so we must always resolve a batch.
        if ($remaining > 0) {
            // Prefer the last-used batch; fall back to any batch for this product.
            $fallback = $batches->last()
                ?? Goods::whereHas('supplyItem', fn($q) => $q->where('product_id', $productId))
                    ->where('shop_id', $invoice->shop_id)
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

            if ($fallback) {
                $fallback->decrement('current_quantity', $remaining);

                InvoiceItem::create([
                    'invoice_id'         => $invoice->id,
                    'product_id'         => $productId,
                    'parent_product_id'  => $parentProductId,
                    'role'               => $role,
                    'goods_id'           => $fallback->id,
                    'quantity'           => $remaining,
                    'price'              => $price,
                ]);
            }
            // If no batch exists at all, the invoice item is simply skipped —
            // this product was never received into this shop.
        }
    }

    // ── Coexistence pricing: per-item engine, with legacy fallback ─────────────

    /**
     * Price the invoice items and return [items(with 'price'), effectiveTotal].
     *
     * New engine — used only when EVERY item has a configured price:
     *   oil  → category.price_per_gram   (Product Type pricing_source = category)
     *   non-oil → product.selling_price  (Product Type pricing_source = product)
     *   line total = quantity × unit price; invoice total = Σ line totals.
     *
     * Otherwise the legacy global-total distribution runs unchanged, so existing
     * (unconfigured) data keeps working exactly as before. The category minimum
     * remains the price floor for both engines (checked in step 5).
     *
     * @return array{0: array, 1: float}
     */
    private function priceInvoiceItems(array $data, \Illuminate\Support\Collection $products): array
    {
        $items = $data['items'];

        // Explicit Global-Total mode: the cashier can always fall back to the
        // legacy global-total workflow on demand (legacy products / special
        // cases), even when items happen to be configured.
        if (($data['pricing_mode'] ?? 'auto') === 'global') {
            $total = (float) ($data['total_amount'] ?? 0);
            return [$this->distributeGlobalTotal($total, $items, $products), round($total, 2)];
        }

        // Manual per-line prices: when the cashier provides a price for every
        // line, use those directly (no distribution). Total = Σ qty × price.
        $allHavePrice = collect($items)->every(
            fn ($it) => isset($it['price']) && $it['price'] !== null && $it['price'] !== '' && (float) $it['price'] >= 0
        );
        if ($allHavePrice) {
            $total = 0.0;
            foreach ($items as &$item) {
                $item['price'] = (float) $item['price'];
                $total        += (float) $item['quantity'] * $item['price'];
            }
            unset($item);

            return [$items, round($total, 2)];
        }

        $allConfigured = true;
        $configured    = [];

        foreach ($items as $item) {
            $product = $products[$item['product_id']] ?? null;
            $price   = $product ? $this->resolveConfiguredUnitPrice($product) : null;
            $configured[$item['product_id']] = $price;
            if ($price === null) {
                $allConfigured = false;
            }
        }

        if ($allConfigured) {
            $total = 0.0;
            foreach ($items as &$item) {
                $price          = (float) $configured[$item['product_id']];
                $item['price']  = $price;
                $total         += (float) $item['quantity'] * $price;
            }
            unset($item);

            return [$items, round($total, 2)];
        }

        // Legacy fallback — distribute the seller-entered global total.
        $total = (float) ($data['total_amount'] ?? 0);
        $items = $this->distributeGlobalTotal($total, $items, $products);

        return [$items, round($total, 2)];
    }

    /**
     * The product's configured unit price under the new engine, or null when it
     * has not been configured yet (→ triggers legacy fallback). Behavior is
     * driven purely by the Product Type — never by category name.
     */
    private function resolveConfiguredUnitPrice(Product $product): ?float
    {
        $category = $product->category;
        $type     = $category?->productType;
        if (! $type) {
            return null;
        }

        if ($type->pricing_source === 'category') {
            // Oil: price per gram now lives on the product. Falls back to the
            // (legacy) category price_per_gram so pre-refactor data still sells.
            if ($product->price_per_gram !== null) {
                return (float) $product->price_per_gram;
            }
            return $category->price_per_gram !== null ? (float) $category->price_per_gram : null;
        }

        if ($type->pricing_source === 'product') {
            // Non-oil: selling price lives on the product.
            return $product->selling_price !== null ? (float) $product->selling_price : null;
        }

        return null;
    }

    // ── Distribute global total across items (A→B→C→D pipeline) ─────────────

    private function distributeGlobalTotal(float $totalAmount, array $items, \Illuminate\Support\Collection $products): array
    {
        // ── Step A: assign fixed prices & accumulate fixed total ──────────────
        $fixedTotal = 0.0;
        foreach ($items as &$item) {
            $category = $products[$item['product_id']]?->category;
            if ($category && $this->categoryIsFixed($category)) {
                $unitPrice     = (float) $category->minimum_sell_price;
                $item['price'] = $unitPrice;
                $fixedTotal   += $unitPrice * (float) $item['quantity'];
            }
        }
        unset($item);

        $remainingPool = round($totalAmount - $fixedTotal, 2);

        if ($remainingPool < 0) {
            abort(422, sprintf(
                'إجمالي المنتجات ذات السعر الثابت (%.2f ج.م) يتجاوز إجمالي الفاتورة المُدخل (%.2f ج.م).',
                $fixedTotal, $totalAmount
            ));
        }

        // ── Collect weighted item indices ─────────────────────────────────────
        $weightedIndices = [];
        $totalRelative   = 0.0;

        foreach ($items as $idx => &$item) {
            $category = $products[$item['product_id']]?->category;
            if ($category && ! $this->categoryIsFixed($category)) {
                $pct              = (float) ($category->value_percentage ?? 0);
                $relative         = (float) $item['quantity'] * ($pct / 100);
                $item['_rel']     = $relative;
                $totalRelative   += $relative;
                $weightedIndices[] = $idx;
            }
        }
        unset($item);

        // ── Guard: remaining pool with no weighted items to absorb it ─────────
        if ($remainingPool > 0 && empty($weightedIndices)) {
            abort(422, sprintf(
                'المبلغ المُدخل (%.2f ج.م) يتجاوز إجمالي الأصناف ذات السعر الثابت (%.2f ج.م) ولا توجد أصناف موزونة لاستيعاب الفارق.',
                $totalAmount, $fixedTotal
            ));
        }

        // ── Guard: weighted items exist but all have value_percentage = 0 ─────
        if (! empty($weightedIndices) && $totalRelative == 0.0) {
            abort(422, 'لا يمكن توزيع القيمة: نسبة القيمة لجميع الأصناف الموزونة تساوي صفر، يرجى مراجعة إعدادات الفئات.');
        }

        // ── Steps C & D: distribute remaining pool, last item absorbs remainder
        $distributed = 0.0;
        $lastIdx     = end($weightedIndices);

        foreach ($weightedIndices as $idx) {
            $qty      = (float) $items[$idx]['quantity'];
            $relative = (float) $items[$idx]['_rel'];

            if ($idx === $lastIdx) {
                $share = round($remainingPool - $distributed, 2);
            } else {
                $share = round(($relative / $totalRelative) * $remainingPool, 2);
            }

            $items[$idx]['price'] = $qty > 0 ? round($share / $qty, 4) : 0;
            $distributed         += $share;
            unset($items[$idx]['_rel']);
        }

        return $items;
    }

    // ── Seller invoice history with filters ───────────────────────────────────

    public function getSellerInvoices(int $sellerId, array $filters, int $perPage): mixed
    {
        $query = Invoice::with([
            'customer',
            'shop:id,name',
            'items.product:id,name,sku',
            'items.parentProduct:id,name',
        ])->where('seller_id', $sellerId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Cashier: search products that HAVE a saved recipe (for compose) ───────
    public function searchComposableProducts(?string $search, int $limit = 15): mixed
    {
        return Product::query()
            ->where('is_active', true)
            ->has('components')
            ->search($search)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'barcode']);
    }

    // ── Cashier: resolve a product's recipe (BOM) components ──────────────────
    // Returns each component enriched with its configured price, unit and the
    // available shop stock, so the compose modal can build editable lines.
    public function resolveProductComponents(int $shopId, int $productId): array
    {
        $product = Product::with([
            'components.component:id,name,sku,scalar,category_id,selling_price,price_per_gram',
            'components.component.category:id,minimum_sell_price,product_type_id',
            'components.component.category.productType:id,sold_by,pricing_source,default_unit',
        ])->find($productId);

        if (! $product) {
            return [];
        }

        return $product->components->map(function ($c) use ($shopId) {
            $comp = $c->component;
            $stock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $comp->id))
                ->where('shop_id', $shopId)
                ->sum('current_quantity');
            $type = optional($comp->category)->productType;

            return [
                'component_product_id'  => $comp->id,
                'name'                  => $comp->name,
                'sku'                   => $comp->sku,
                'unit'                  => $type->default_unit ?? ($comp->scalar ?? ''),
                'quantity'              => (float) $c->quantity,   // per 1 parent (or default suggestion, when variable)
                'configured_unit_price' => $this->resolveConfiguredUnitPrice($comp),
                'shop_stock'            => $stock,
                'is_variable_quantity'  => (bool) $c->is_variable_quantity,
                'component_group'       => $c->component_group,
            ];
        })->all();
    }

    // ── Cashier: catalog products (Compound + Ready) ─────────────────────────
    // Deliberately separate from searchGoods() so that endpoint's behavior
    // never changes — this is purely additive. Returns both READY_PRODUCT
    // (added to the invoice directly, unchanged behavior) and COMPOUND
    // (opens the Product Builder — no recipe/components involved at all;
    // the seller freely picks any oil + bottle at sale time). Raw materials
    // and packaging never appear here regardless of stock, since they're
    // never marked show_in_catalog.
    public function searchCatalogProducts(int $shopId, ?string $search, int $limit = 30): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->with(['category:id,minimum_sell_price,product_type_id', 'category.productType:id,sold_by,pricing_source,default_unit'])
            ->search($search)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'barcode', 'image', 'product_type', 'selling_price', 'category_id', 'scalar', 'default_oil_id']);

        if ($products->isEmpty()) {
            return [];
        }

        // READY_PRODUCT items need a price + shop stock so they can be added
        // directly (unchanged behavior); COMPOUND items ignore these — their
        // price/stock come from the chosen oil+bottle in the Product Builder.
        $readyIds = $products->where('product_type', Product::TYPE_READY_PRODUCT)->pluck('id');
        $stocks = $readyIds->isEmpty() ? collect() : Goods::query()
            ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
            ->whereIn('supply_items.product_id', $readyIds)
            ->where('goods.shop_id', $shopId)
            ->groupBy('supply_items.product_id')
            ->selectRaw('supply_items.product_id as pid, SUM(goods.current_quantity) as total')
            ->pluck('total', 'pid');

        // Compound Products have no stock/price of their own (the Builder computes
        // both live from whatever oil+bottle the seller picks), but if the shop has
        // NO priced, in-stock oil or NO priced, in-stock bottle at all, no compound
        // can be composed regardless of which one is opened — computed once here,
        // not per-product, and only when at least one compound is in this page.
        $compoundAvailability = $products->contains('product_type', Product::TYPE_COMPOUND)
            ? $this->compoundComposability($shopId)
            : null;

        return $products->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode,
            'image' => $p->image, 'product_type' => $p->product_type,
            'configured_unit_price' => $p->product_type === Product::TYPE_READY_PRODUCT ? $this->resolveConfiguredUnitPrice($p) : null,
            'shop_stock' => $p->product_type === Product::TYPE_READY_PRODUCT ? (float) ($stocks[$p->id] ?? 0) : null,
            'unit' => $p->scalar,
            // Phase 7 — Composite Products only: a preferred oil to pre-select (never
            // lock) in the Assemble-on-Sale dialog. Null for every other product type.
            'default_oil_id' => $p->product_type === Product::TYPE_COMPOUND ? $p->default_oil_id : null,
            'compound_available' => $p->product_type === Product::TYPE_COMPOUND ? $compoundAvailability['available'] : null,
            'compound_unavailable_reason' => $p->product_type === Product::TYPE_COMPOUND ? $compoundAvailability['reason'] : null,
        ])->values()->all();
    }

    /** Whether this shop currently has at least one priced, in-stock oil AND
     *  at least one priced, in-stock bottle — the minimum needed to compose
     *  ANY Compound Product (the Builder lets the seller pick any oil/bottle,
     *  there's no fixed recipe, so this check is shop-wide, not per-compound). */
    private function compoundComposability(int $shopId): array
    {
        $oilAvailable = collect($this->searchOilProducts($shopId, null, 500))
            ->contains(fn ($o) => $o['configured_unit_price'] !== null && $o['shop_stock'] > 0);
        $bottleAvailable = collect($this->searchBottleProducts($shopId, null, 500))
            ->contains(fn ($b) => $b['configured_unit_price'] !== null && $b['shop_stock'] > 0);

        if ($oilAvailable && $bottleAvailable) {
            return ['available' => true, 'reason' => null];
        }
        if (! $oilAvailable && ! $bottleAvailable) {
            return ['available' => false, 'reason' => 'لا توجد زيوت أو زجاجات متاحة ومُسعَّرة للتركيب في هذا الفرع.'];
        }
        if (! $oilAvailable) {
            return ['available' => false, 'reason' => 'لا يوجد أي زيت متاح ومُسعَّر للتركيب في هذا الفرع.'];
        }

        return ['available' => false, 'reason' => 'لا توجد أي زجاجة متاحة ومُسعَّرة للتركيب في هذا الفرع.'];
    }

    // ── Product Builder: raw materials priced as "oil" (per gram) ───────────
    // Any RAW_MATERIAL whose category prices per-gram (pricing_source =
    // 'category') — the seller is completely free to pick any of these, no
    // predefined recipe restricts the choice.
    public function searchOilProducts(int $shopId, ?string $search, int $limit = 30): mixed
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('product_type', Product::TYPE_RAW_MATERIAL)
            ->whereHas('category.productType', fn ($q) => $q->where('pricing_source', 'category'))
            ->with(['category:id,minimum_sell_price,product_type_id', 'category.productType:id,sold_by,pricing_source,default_unit'])
            ->search($search)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'scalar', 'category_id', 'price_per_gram']);

        return $this->decorateForBuilder($products, $shopId);
    }

    // ── Product Builder: packaging bottles (capacity_ml required) ───────────
    public function searchBottleProducts(int $shopId, ?string $search, int $limit = 30): mixed
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('product_type', Product::TYPE_PACKAGING)
            ->whereNotNull('capacity_ml')
            ->with(['category:id,minimum_sell_price,product_type_id', 'category.productType:id,sold_by,pricing_source,default_unit'])
            ->search($search)
            ->orderBy('capacity_ml')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'scalar', 'category_id', 'selling_price', 'capacity_ml']);

        return $this->decorateForBuilder($products, $shopId);
    }

    /** Attach configured_unit_price + shop_stock to a small product list (Builder pickers). */
    private function decorateForBuilder(\Illuminate\Support\Collection $products, int $shopId): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $stocks = Goods::query()
            ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
            ->whereIn('supply_items.product_id', $products->pluck('id'))
            ->where('goods.shop_id', $shopId)
            ->groupBy('supply_items.product_id')
            ->selectRaw('supply_items.product_id as pid, SUM(goods.current_quantity) as total')
            ->pluck('total', 'pid');

        return $products->map(fn ($p) => [
            'id'                    => $p->id,
            'name'                  => $p->name,
            'sku'                   => $p->sku,
            'unit'                  => $p->scalar,
            'capacity_ml'           => $p->capacity_ml !== null ? (float) $p->capacity_ml : null,
            'configured_unit_price' => $this->resolveConfiguredUnitPrice($p),
            'shop_stock'            => (float) ($stocks[$p->id] ?? 0),
        ])->values()->all();
    }

    // ── Product Builder: reference cost calculation + bottle-capacity
    //    validation. No selling price is computed or suggested here — the
    //    seller types it by hand in the Builder; oil/bottle cost + stock are
    //    informational only.
    public function calculateCompoundPrice(
        int $shopId, int $catalogProductId, int $oilProductId, float $oilQty, int $bottleProductId,
        ?int $alcoholProductId = null, ?float $alcoholQty = null,
    ): array {
        $catalog = Product::findOrFail($catalogProductId);
        $oil     = Product::with('category.productType')->findOrFail($oilProductId);
        $bottle  = Product::with('category.productType')->findOrFail($bottleProductId);

        if ($bottle->capacity_ml !== null && $oilQty > (float) $bottle->capacity_ml) {
            abort(422, 'الكمية المطلوبة من الزيت أكبر من سعة الزجاجة المختارة.');
        }

        $oilUnitPrice    = $this->resolveConfiguredUnitPrice($oil) ?? 0.0;
        $bottleUnitPrice = $this->resolveConfiguredUnitPrice($bottle) ?? 0.0;

        $oilCost    = round($oilQty * $oilUnitPrice, 2);
        $bottleCost = round($bottleUnitPrice, 2);

        $oilStock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $oil->id))
            ->where('shop_id', $shopId)->sum('current_quantity');
        $bottleStock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $bottle->id))
            ->where('shop_id', $shopId)->sum('current_quantity');

        // Business rule: Alcohol is a real, fully-costed Raw Material — it has its own
        // purchase cost, FIFO cost, and inventory value, exactly like Oil or Bottle
        // (never priced at zero, never dropped from accounting). The ONLY thing that
        // changes is that its cost is excluded from the Composite Product's COMMERCIAL
        // pricing/profit shown to the cashier: total_cost below stays Oil + Bottle only.
        // Its real unit price is still returned (alcohol_unit_price/alcohol_cost) so the
        // invoice line recorded for it carries its true value, not a fake zero.
        $alcohol = null;
        $alcoholUnitPrice = 0.0;
        $alcoholCost = 0.0;
        $alcoholStock = 0.0;
        if ($alcoholProductId && $alcoholQty !== null) {
            $alcohol = Product::with('category.productType')->findOrFail($alcoholProductId);
            $alcoholUnitPrice = $this->resolveConfiguredUnitPrice($alcohol) ?? 0.0;
            $alcoholCost = round($alcoholQty * $alcoholUnitPrice, 2);
            $alcoholStock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $alcohol->id))
                ->where('shop_id', $shopId)->sum('current_quantity');
        }

        // Deliberately Oil + Bottle only — this is the "commercial" cost/profit basis
        // shown to the cashier; Alcohol's real cost (above) never enters it.
        $totalCost = round($oilCost + $bottleCost, 2);
        $stockOk = $oilStock >= $oilQty && $bottleStock >= 1
            && (! $alcohol || $alcoholStock >= $alcoholQty);

        return [
            'oil_unit_price'    => $oilUnitPrice,
            'oil_cost'          => $oilCost,
            'oil_stock'         => $oilStock,
            'bottle_unit_price' => $bottleUnitPrice,
            'bottle_cost'       => $bottleCost,
            'bottle_stock'      => $bottleStock,
            'bottle_capacity_ml' => $bottle->capacity_ml !== null ? (float) $bottle->capacity_ml : null,
            // Real values (never zero) — used only to record Alcohol's true cost on its
            // own invoice line; deliberately NOT folded into total_cost/stock_ok's basis.
            'alcohol_unit_price' => $alcohol ? $alcoholUnitPrice : null,
            'alcohol_cost'       => $alcohol ? $alcoholCost : null,
            'alcohol_stock'      => $alcohol ? $alcoholStock : null,
            'total_cost'        => $totalCost,
            'stock_ok'          => $stockOk,
            // Pricing Management's stored default — pre-fills the Builder's
            // Selling Price field. Never written back here; only Pricing
            // Management can change it (see PricingService::updateSellingPrice).
            'default_selling_price' => $catalog->default_selling_price !== null ? (float) $catalog->default_selling_price : null,
        ];
    }

    // ── Product Builder: raw materials priced as "alcohol" carrier ─────────
    // Distinct "كحول" category (Phase 8) — deliberately separate from the
    // broader "قواعد وحوامل" bucket, which also holds non-alcohol carriers
    // (DPG, jojoba, IPM, neutral base) that must never appear in this picker.
    // Chosen exactly like an oil: freely, every sale, never a fixed product.
    public function searchAlcoholProducts(int $shopId, ?string $search, int $limit = 30): mixed
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('product_type', Product::TYPE_RAW_MATERIAL)
            ->whereHas('category', fn ($q) => $q->where('name', 'كحول'))
            ->with(['category:id,minimum_sell_price,product_type_id', 'category.productType:id,sold_by,pricing_source,default_unit'])
            ->search($search)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'scalar', 'category_id', 'selling_price']);

        return $this->decorateForBuilder($products, $shopId);
    }

    // ── Admin: cross-shop invoice review (pending queue, etc.) ────────────────

    public function getInvoicesForAdmin(array $filters, int $perPage): mixed
    {
        $query = Invoice::with([
            'customer',
            'seller:id,name',
            'shop:id,name',
            'items.product:id,name,sku,scalar,category_id',
            'items.product.category:id,name,minimum_sell_price',
            'items.parentProduct:id,name',
            'payments.currency:id,code,symbol',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['shop_id'])) {
            $query->where('shop_id', (int) $filters['shop_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Update invoice status ─────────────────────────────────────────────────

    public function updateStatus(Invoice $invoice, string $status): Invoice
    {
        if ($invoice->status === 'cancelled') {
            abort(422, 'لا يمكن تعديل فاتورة ملغاة');
        }

        if ($invoice->status === 'approved' && $status === 'cancelled') {
            abort(422, 'لا يمكن إلغاء فاتورة معتمدة بعد الاعتماد');
        }

        $invoice->update(['status' => $status]);

        return $invoice->fresh(['customer', 'items.product:id,name,sku', 'items.parentProduct:id,name']);
    }
}
