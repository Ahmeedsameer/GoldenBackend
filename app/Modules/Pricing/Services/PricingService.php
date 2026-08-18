<?php

namespace App\Modules\Pricing\Services;

use App\Models\Goods;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\User;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Support\Facades\DB;

/**
 * Pricing Management — the single place selling prices live and change.
 *
 * Products remain managed in Product Management; inventory/cost still flows
 * entirely from Purchasing + FIFO (untouched). This service only reads costs
 * and writes prices — it never touches Goods/SupplyItem/FIFO consumption.
 *
 * Ready Products: cost is trackable (fixed inventory, fixed purchase price),
 * so Pricing keeps a cost snapshot (`priced_cost`) and flags drift against
 * the live latest purchase cost until the admin explicitly runs
 * "Update Prices" — purchasing alone never changes `selling_price`.
 *
 * Compound Products: composition is chosen fresh every sale (any oil, any
 * bottle, any quantity — see SalesService::calculateCompoundPrice), so there
 * is no single "cost" to track outside of an actual sale. Pricing only holds
 * a `default_selling_price` that pre-fills the Sales Product Builder; the
 * seller can still override it per invoice without ever changing this value.
 */
class PricingService
{
    public function __construct(
        private SalesService $salesService,
    ) {}

    // ── Display price cache (Product.current_display_price) ──────────────────

    /**
     * Re-syncs the read-only Product.current_display_price cache from the
     * live current-FIFO-price resolver (SalesService::currentWarehouseFifoPrice)
     * — never computes pricing itself, just persists what the live resolver
     * already says. Call this at the end of any operation that can change
     * which batch is oldest-sellable in the warehouse: a sale, a purchase, a
     * batch being priced/edited/archived, a transfer, an inventory
     * adjustment, a waste record, or an invoice cancellation.
     */
    public function syncDisplayPrice(Product $product): void
    {
        if (! $product->isBatchPriced()) {
            return; // legacy flat-price products never had a live FIFO price to cache
        }

        $price = $this->salesService->currentWarehouseFifoPrice($product->id);
        // Avoid an unconditional write on every single sale/purchase when
        // nothing actually changed — cheap guard, still correct either way.
        if (round((float) ($product->current_display_price ?? -1), 2) !== round((float) ($price ?? -1), 2)) {
            $product->update(['current_display_price' => $price]);
        }
    }

    // ── Cost lookups (read-only, never touches inventory) ────────────────────

    public function latestPurchaseCost(int $productId): ?float
    {
        $unitPrice = SupplyItem::query()
            ->join('supplies', 'supplies.id', '=', 'supply_items.supply_id')
            ->where('supply_items.product_id', $productId)
            ->orderByDesc('supplies.date')
            ->orderByDesc('supply_items.id')
            ->value('supply_items.unit_price');

        return $unitPrice !== null ? (float) $unitPrice : null;
    }

    public function averagePurchaseCost(int $productId): ?float
    {
        $row = SupplyItem::query()
            ->where('product_id', $productId)
            ->selectRaw('SUM(quantity * unit_price) as total_cost, SUM(quantity) as total_qty')
            ->first();

        if (! $row || (float) $row->total_qty <= 0) {
            return null;
        }

        return round((float) $row->total_cost / (float) $row->total_qty, 2);
    }

    // ── Pricing table (Compound, Ready, Raw Material, Packaging) ─────────────

    public function listPricing(?string $search = null): array
    {
        $products = Product::query()
            ->whereIn('product_type', [
                Product::TYPE_COMPOUND, Product::TYPE_READY_PRODUCT,
                Product::TYPE_RAW_MATERIAL, Product::TYPE_PACKAGING,
            ])
            ->notArchived()
            ->with('category.productType:id,pricing_source')
            ->search($search)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'scalar', 'product_type', 'category_id', 'selling_price', 'price_per_gram', 'default_selling_price', 'priced_cost', 'priced_at', 'is_active']);

        return $products->map(fn (Product $p) => $this->rowFor($p))->values()->all();
    }

    /** Raw Materials whose category prices per-gram (oils) are edited via
     *  `price_per_gram` — every other priceable type (Ready Products,
     *  Packaging/bottles, and product-priced Raw Materials like Alcohol) uses
     *  `selling_price`, exactly the field Sales reads via
     *  SalesService::resolveConfiguredUnitPrice(). */
    private function isPricedByGram(Product $product): bool
    {
        return optional($product->category?->productType)->pricing_source === 'category';
    }

    public function rowFor(Product $product): array
    {
        // Batch-aware pricing (Ready Products, Packaging, and any future
        // inventory item priced per-batch — Product::isBatchPriced()): a flat
        // `selling_price`/`status` no longer means anything — every purchase
        // batch (SupplyItem) carries its own immutable price. See
        // batchRowFor()/batchStatusFor()/listBatches().
        if ($product->isBatchPriced()) {
            return $this->batchRowFor($product);
        }

        if ($product->product_type === Product::TYPE_COMPOUND) {
            $price = $product->default_selling_price !== null ? (float) $product->default_selling_price : null;

            return [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'product_type' => $product->product_type,
                'pricing_field' => 'default_selling_price',
                'unit' => $product->scalar,
                'current_cost' => null,
                'selling_price' => $price,
                'estimated_profit' => null,
                'profit_percent' => null,
                'last_price_update' => $product->priced_at?->toIso8601String(),
                'status' => $price === null ? 'no_price' : 'ok',
            ];
        }

        $pricedByGram = $this->isPricedByGram($product);

        $liveCost   = $this->latestPurchaseCost($product->id);
        $pricedCost = $product->priced_cost !== null ? (float) $product->priced_cost : null;
        $price      = $pricedByGram
            ? ($product->price_per_gram !== null ? (float) $product->price_per_gram : null)
            : ($product->selling_price !== null ? (float) $product->selling_price : null);

        // The cost shown in the table is the *snapshot* Pricing is working
        // from (pricedCost) — purchasing new stock never silently changes
        // it. Falls back to the live cost only if nothing has been priced yet.
        $displayCost = $pricedCost ?? $liveCost;

        $profit  = ($price !== null && $displayCost !== null) ? round($price - $displayCost, 2) : null;
        $profitPct = ($profit !== null && $displayCost > 0) ? round($profit / $displayCost * 100, 1) : null;

        $needsReview = $liveCost !== null && $pricedCost !== null && round($liveCost, 2) !== round($pricedCost, 2);
        $status = $price === null ? 'no_price' : ($needsReview ? 'needs_review' : 'ok');

        return [
            'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'product_type' => $product->product_type,
            'pricing_field' => $pricedByGram ? 'price_per_gram' : 'selling_price',
            'unit' => $product->scalar,
            'current_cost' => $displayCost,
            'selling_price' => $price,
            'estimated_profit' => $profit,
            'profit_percent' => $profitPct,
            'last_price_update' => $product->priced_at?->toIso8601String(),
            'status' => $status,
        ];
    }

    // ── Batch-aware pricing (Ready Products, Packaging, any future inventory
    //    item priced per-batch) — every purchase batch (SupplyItem) carries its
    //    OWN immutable selling_price. Automatic status flow:
    //    Product Created → Waiting For First Supply → (first supply arrives)
    //    → Needs Initial Pricing → (priced) → Priced → (new supply arrives)
    //    → Pricing Update Required → (new batch priced) → Priced. ───────────

    /** Product-level batch summary: status, batch counts, latest supply/pricing dates. */
    public function batchStatusFor(Product $product): array
    {
        $batches = $product->supplyItems; // hasMany, ordered oldest-first
        $total    = $batches->count();
        // Archived batches are retired — they don't count toward "needs
        // pricing" (no point nagging about a batch that will never sell
        // again), but they DO still count in batches_total for an honest
        // history count.
        $active   = $batches->whereNull('archived_at');
        $priced   = $active->whereNotNull('selling_price')->count();
        $unpriced = $active->count() - $priced;

        if (! $product->is_active) {
            $status = 'inactive';
        } elseif ($total === 0) {
            $status = 'waiting_for_first_supply';
        } elseif ($priced === 0 && $unpriced > 0) {
            $status = 'needs_initial_pricing';
        } elseif ($unpriced > 0) {
            $status = 'pricing_update_required';
        } else {
            $status = 'priced';
        }

        return [
            'status'              => $status,
            'batches_total'       => $total,
            'batches_priced'      => $priced,
            'batches_unpriced'    => $unpriced,
            'needs_pricing'       => $unpriced > 0,
            'latest_supply_date'  => $batches->max('supply_date'),
            'latest_pricing_date' => optional($batches->pluck('priced_at')->filter()->max())?->toIso8601String(),
        ];
    }

    /**
     * System-wide snapshot of pending pricing work, split by the same
     * lifecycle distinction as pricing_queue (batchRowFor()): batches whose
     * product has never had ANY priced batch ('first_pricing') vs batches
     * whose product already has at least one priced batch elsewhere
     * ('pricing_required'). Recomputed fresh on every call — never cached —
     * so it always reflects the real current backend state; used to keep
     * the "batches need pricing" Admin notification's count accurate
     * (e.g. refreshing 3 → 8) instead of just describing one event.
     */
    public function pendingPricingSummary(): array
    {
        $unpriced = SupplyItem::query()
            ->whereNull('selling_price')
            ->whereNull('archived_at')
            ->with('product:id,name')
            ->get(['id', 'product_id']);

        $empty = ['count' => 0, 'product_ids' => [], 'product_names' => []];
        if ($unpriced->isEmpty()) {
            return ['first_pricing' => $empty, 'pricing_required' => $empty];
        }

        $productIds = $unpriced->pluck('product_id')->unique()->values();
        $pricedProductIds = SupplyItem::whereIn('product_id', $productIds)
            ->whereNotNull('selling_price')
            ->whereNull('archived_at')
            ->distinct()
            ->pluck('product_id')
            ->flip();

        $firstPricing = $unpriced->filter(fn ($item) => ! $pricedProductIds->has($item->product_id));
        $pricingRequired = $unpriced->filter(fn ($item) => $pricedProductIds->has($item->product_id));

        $summarize = fn ($items) => [
            'count' => $items->count(),
            'product_ids' => $items->pluck('product_id')->unique()->values()->all(),
            'product_names' => $items->pluck('product.name')->filter()->unique()->values()->all(),
        ];

        return [
            'first_pricing' => $summarize($firstPricing),
            'pricing_required' => $summarize($pricingRequired),
        ];
    }

    /**
     * Every batch for this product, oldest-first, with its own remaining
     * quantity (summed across every shop's Goods rows) plus the three figures
     * a manager needs to know what customers pay today vs. what kicks in
     * next: current_fifo_price (oldest priced batch that still has stock),
     * next_batch_price (the very next batch in line, priced or not — so an
     * unpriced arrival is immediately visible), remaining_in_current_batch.
     */
    public function listBatches(Product $product): array
    {
        // Ordered exactly like real FIFO consumption (see Product::supplyItems()
        // docblock) — by the parent Supply's date/id, not SupplyItem.created_at.
        $batches = SupplyItem::where('supply_items.product_id', $product->id)
            ->join('supplies', 'supplies.id', '=', 'supply_items.supply_id')
            ->with(['supply:id,date', 'pricedBy:id,name'])
            ->orderBy('supplies.date')
            ->orderBy('supply_items.id')
            ->select('supply_items.*')
            ->get();

        $remainingByBatch = $batches->isEmpty() ? collect() : Goods::whereIn('supply_item_id', $batches->pluck('id'))
            ->selectRaw('supply_item_id, SUM(current_quantity) as remaining')
            ->groupBy('supply_item_id')
            ->pluck('remaining', 'supply_item_id');

        $rows = $batches->map(function (SupplyItem $b) use ($remainingByBatch) {
            $remaining = (float) ($remainingByBatch[$b->id] ?? 0);

            return [
                'id'                 => $b->id,
                'supply_date'        => $b->supply?->date,
                'purchase_cost'      => (float) $b->unit_price,
                'selling_price'      => $b->selling_price !== null ? (float) $b->selling_price : null,
                'quantity_received'  => (float) $b->quantity,
                'remaining_quantity' => $remaining,
                'is_priced'          => $b->isPriced(),
                'is_archived'        => $b->isArchived(),
                'priced_at'          => $b->priced_at?->toIso8601String(),
                'priced_by'          => $b->pricedBy?->name,
                // Placeholder — replaced below once we know which single batch
                // is actually the one FIFO is currently drawing from.
                'status'             => ! $b->isPriced() ? 'waiting_for_pricing' : 'queued',
            ];
        })->values();

        // Exactly ONE batch is ever "active" — the oldest priced, non-archived
        // batch that still has stock (what fifoBatchesQuery()/processItem()
        // would drain from next). Every other priced batch with stock is
        // merely "queued" (in stock, waiting its turn); a priced batch with
        // none left is "depleted". An archived batch is always "archived" —
        // permanently retired from sale, regardless of remaining stock —
        // never deleted, so it's still fully visible in this history.
        $activeIdx = $rows->search(fn ($r) => $r['is_priced'] && ! $r['is_archived'] && $r['remaining_quantity'] > 0);
        $active    = $activeIdx !== false ? $rows[$activeIdx] : null;

        $rows = $rows->map(function ($r, $i) use ($activeIdx) {
            if ($r['is_archived']) {
                $r['status'] = 'archived';
                return $r;
            }
            if (! $r['is_priced']) {
                return $r;
            }
            $r['status'] = $i === $activeIdx ? 'active' : ($r['remaining_quantity'] > 0 ? 'queued' : 'depleted');
            return $r;
        });

        $next = $active
            ? $rows->slice($activeIdx + 1)->first(fn ($r) => ! $r['is_archived'] && $r['remaining_quantity'] > 0)
            : $rows->first(fn ($r) => ! $r['is_archived'] && $r['remaining_quantity'] > 0);

        return [
            'batches'                     => $rows->all(),
            'current_fifo_price'          => $active['selling_price'] ?? null,
            'next_batch_price'            => $next['selling_price'] ?? null,
            'remaining_in_current_batch'  => $active['remaining_quantity'] ?? null,
            // Category pricing helpers — for the pricing dialog to pre-fill
            // (default) and validate against (minimum) client-side, before
            // the same floor is re-checked authoritatively server-side.
            'category_minimum_price'      => $product->category?->minimum_sell_price !== null ? (float) $product->category->minimum_sell_price : null,
            'category_default_price'      => $product->category?->default_selling_price !== null ? (float) $product->category->default_selling_price : null,
        ];
    }

    private function batchRowFor(Product $product): array
    {
        $status       = $this->batchStatusFor($product);
        $batchSummary = $this->listBatches($product);
        $currentStock = (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $product->id))
            ->sum('current_quantity');

        return [
            'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'product_type' => $product->product_type,
            'pricing_field' => 'batch',
            'unit' => $product->scalar,
            'current_inventory' => $currentStock,
            'current_fifo_price' => $batchSummary['current_fifo_price'],
            'next_batch_price' => $batchSummary['next_batch_price'],
            'remaining_in_current_batch' => $batchSummary['remaining_in_current_batch'],
            'batches_total' => $status['batches_total'],
            'batches_priced' => $status['batches_priced'],
            'batches_unpriced' => $status['batches_unpriced'],
            'needs_pricing' => $status['needs_pricing'],
            // Lifecycle distinction the Admin's two Pricing Management queues
            // are built on: a product with ZERO batches ever priced is
            // receiving its very FIRST selling price ('first_pricing'); a
            // product that already has at least one priced batch but also
            // has a newer unpriced one is an existing, already-sellable
            // product just getting a price update on its new stock
            // ('pricing_required'). Null when there's nothing to price.
            'pricing_queue' => ! $status['needs_pricing'] ? null : ($status['batches_priced'] === 0 ? 'first_pricing' : 'pricing_required'),
            'latest_supply_date' => $status['latest_supply_date'],
            'latest_pricing_date' => $status['latest_pricing_date'],
            'status' => $status['status'],
        ];
    }

    /**
     * Prices exactly ONE unpriced batch — immutable once set (422 if already
     * priced; historical batches/prices never change, per the architecture's
     * core guarantee). Writes the batch's selling_price/priced_at/priced_by
     * and a matching PriceHistory audit row.
     */
    public function priceBatch(Product $product, SupplyItem $batch, float $price, ?string $reason, User $admin): void
    {
        if ((int) $batch->product_id !== $product->id) {
            abort(404, 'الدفعة غير تابعة لهذا المنتج.');
        }
        if (! $product->isBatchPriced()) {
            abort(422, 'هذا المنتج لا يُسعَّر بالدفعة — استخدم تعديل سعر البيع العادي.');
        }
        if ($batch->isPriced()) {
            abort(422, 'هذه الدفعة مُسعَّرة بالفعل — الأسعار التاريخية لا يمكن تعديلها أبداً.');
        }
        $this->assertPriceMeetsCategoryMinimum($product, $price);

        DB::transaction(function () use ($product, $batch, $price, $reason, $admin) {
            $batch->update([
                'selling_price' => round($price, 2),
                'priced_at'     => now(),
                'priced_by'     => $admin->id,
            ]);

            PriceHistory::create([
                'product_id'        => $product->id,
                'supply_item_id'    => $batch->id,
                'old_selling_price' => null,
                'new_selling_price' => round($price, 2),
                'type'              => PriceHistory::TYPE_BATCH_PRICING,
                'reason'            => $reason,
                'updated_by'        => $admin->id,
            ]);
        });

        $this->syncDisplayPrice($product);
    }

    /**
     * Edits an ALREADY-priced batch's selling price — a distinct capability
     * from priceBatch() (which stays immutable-on-first-price; its "historical
     * prices never change" guard is unaffected and still fires if this method
     * is bypassed). Only ever affects future sales: every InvoiceItem already
     * created from this batch keeps its own frozen unit_cost/price/line_profit
     * snapshot regardless (see SalesService::processItem()) — nothing here
     * touches invoice_items. priced_at/priced_by stay as the batch's original
     * first-pricing timestamp/user; the new PriceHistory row (with a real
     * old_selling_price, unlike the first-pricing row which has none) is the
     * audit trail for "when/who/why this batch's price was edited."
     */
    public function updateBatchPrice(Product $product, SupplyItem $batch, float $price, ?string $reason, User $admin): void
    {
        if ((int) $batch->product_id !== $product->id) {
            abort(404, 'الدفعة غير تابعة لهذا المنتج.');
        }
        if (! $product->isBatchPriced()) {
            abort(422, 'هذا المنتج لا يُسعَّر بالدفعة — استخدم تعديل سعر البيع العادي.');
        }
        if (! $batch->isPriced()) {
            abort(422, 'هذه الدفعة لم تُسعَّر بعد — استخدم شاشة التسعير الأولي.');
        }
        $this->assertPriceMeetsCategoryMinimum($product, $price);

        $oldPrice = $batch->selling_price !== null ? (float) $batch->selling_price : null;

        DB::transaction(function () use ($product, $batch, $price, $oldPrice, $reason, $admin) {
            $batch->update(['selling_price' => round($price, 2)]);

            PriceHistory::create([
                'product_id'        => $product->id,
                'supply_item_id'    => $batch->id,
                'old_selling_price' => $oldPrice,
                'new_selling_price' => round($price, 2),
                'type'              => PriceHistory::TYPE_BATCH_PRICE_EDIT,
                'reason'            => $reason,
                'updated_by'        => $admin->id,
            ]);
        });

        $this->syncDisplayPrice($product);
    }

    /**
     * Bulk pricing — lets the Admin select several batches (across one or many
     * products) that need pricing and submit them together, but each batch
     * keeps its OWN price; this is a convenience wrapper around priceBatch()
     * called once per item, never a single shared price applied to everything.
     * Each item is independent: one invalid/already-priced/below-minimum item
     * does not abort the others — every result (success or the specific
     * failure reason) is reported back so the admin can see exactly what
     * happened per batch. Reuses priceBatch() verbatim, so every existing
     * guard (immutable-once-priced, category minimum, batch-priced-only)
     * still applies identically to each item.
     *
     * @param  array<int, array{supply_item_id:int, selling_price:float, reason?:?string}>  $items
     * @return array<int, array{supply_item_id:int, success:bool, message:string}>
     */
    public function bulkPriceBatches(array $items, User $admin): array
    {
        $results = [];

        foreach ($items as $item) {
            $supplyItemId = (int) $item['supply_item_id'];

            try {
                $batch = SupplyItem::findOrFail($supplyItemId);
                $product = Product::findOrFail($batch->product_id);

                $this->priceBatch($product, $batch, (float) $item['selling_price'], $item['reason'] ?? null, $admin);

                $results[] = ['supply_item_id' => $supplyItemId, 'success' => true, 'message' => 'تم تسعير الدفعة بنجاح'];
            } catch (\Throwable $e) {
                $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    ? $e->getMessage()
                    : 'تعذر تسعير هذه الدفعة.';
                $results[] = ['supply_item_id' => $supplyItemId, 'success' => false, 'message' => $message];
            }
        }

        return $results;
    }

    /** Shared by priceBatch()/updateBatchPrice() — the category minimum is the
     *  floor for a batch's selling price, exactly as it already is for the
     *  legacy flat-price engines (SalesService's distribution step 5). */
    private function assertPriceMeetsCategoryMinimum(Product $product, float $price): void
    {
        $minimum = $product->category?->minimum_sell_price;
        if ($minimum !== null && round($price, 2) < round((float) $minimum, 2)) {
            abort(422, 'لا يمكن أن يكون سعر بيع الدفعة أقل من الحد الأدنى لسعر بيع الفئة.');
        }
    }

    /**
     * Retires a batch from future sale — Batch Deletion Protection: a batch
     * that already appears in invoice history can NEVER be physically
     * deleted (see SupplyService::delete() + the invoice_items FK's
     * restrictOnDelete), so archiving is the only supported way to "remove"
     * a batch a manager no longer wants sold. It stays fully visible/
     * resolvable in Pricing history and on every past invoice forever;
     * fifoBatchesQuery() simply never drains it again.
     */
    public function archiveBatch(Product $product, SupplyItem $batch): void
    {
        if ((int) $batch->product_id !== $product->id) {
            abort(404, 'الدفعة غير تابعة لهذا المنتج.');
        }
        if ($batch->isArchived()) {
            abort(422, 'هذه الدفعة مؤرشفة بالفعل.');
        }

        $batch->update(['archived_at' => now()]);
        $this->syncDisplayPrice($product);
    }

    // ── "Update Prices" — Ready Products only, admin-triggered, cost-only ────

    /** Products whose live purchase cost differs from the cost Pricing is currently using. */
    public function previewPriceUpdate(): array
    {
        $products = Product::query()
            ->where('product_type', Product::TYPE_READY_PRODUCT)
            ->notArchived()
            ->with('category.productType:id,pricing_source')
            ->get(['id', 'name', 'sku', 'selling_price', 'priced_cost', 'category_id']);

        $changes = [];
        foreach ($products as $product) {
            // Batch-aware pricing has its own per-batch cost (SupplyItem.unit_price)
            // and never a single product-level priced_cost/selling_price snapshot —
            // this legacy cost-refresh flow no longer applies to it at all.
            if ($product->isBatchPriced()) {
                continue;
            }
            $liveCost   = $this->latestPurchaseCost($product->id);
            if ($liveCost === null) {
                continue; // never purchased — nothing to compare against
            }
            $pricedCost = $product->priced_cost !== null ? (float) $product->priced_cost : null;
            if ($pricedCost !== null && round($pricedCost, 2) === round($liveCost, 2)) {
                continue; // unchanged
            }

            $price = $product->selling_price !== null ? (float) $product->selling_price : null;
            $changes[] = [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'old_cost' => $pricedCost, 'new_cost' => round($liveCost, 2),
                'selling_price' => $price,
                'profit_before' => ($price !== null && $pricedCost !== null) ? round($price - $pricedCost, 2) : null,
                'profit_after'  => $price !== null ? round($price - $liveCost, 2) : null,
            ];
        }

        return $changes;
    }

    /**
     * Applies the cost refresh detected by previewPriceUpdate() — recomputed
     * fresh here (not trusted from the client) so a purchase landing between
     * preview and confirm is still reflected correctly. Selling prices are
     * never touched.
     */
    /** @param int[]|null $productIds Restrict to these products; null applies every detected change. */
    public function applyPriceUpdate(User $admin, ?array $productIds = null): array
    {
        $changes = $this->previewPriceUpdate();

        if ($productIds !== null) {
            $allowed = array_flip($productIds);
            $changes = array_values(array_filter($changes, fn ($c) => isset($allowed[$c['id']])));
        }

        DB::transaction(function () use ($changes, $admin) {
            foreach ($changes as $change) {
                $product = Product::find($change['id']);
                if (! $product) {
                    continue;
                }
                PriceHistory::create([
                    'product_id' => $product->id,
                    'old_cost' => $change['old_cost'], 'new_cost' => $change['new_cost'],
                    'old_selling_price' => $change['selling_price'], 'new_selling_price' => $change['selling_price'],
                    'type' => PriceHistory::TYPE_COST_UPDATE,
                    'updated_by' => $admin->id,
                ]);
                $product->update(['priced_cost' => $change['new_cost'], 'priced_at' => now()]);
            }
        });

        return $changes;
    }

    // ── Manual selling-price edit (admin only) — Ready + Compound ────────────

    public function updateSellingPrice(Product $product, float $newPrice, ?string $reason, User $admin): void
    {
        if (! in_array($product->product_type, [
            Product::TYPE_COMPOUND, Product::TYPE_READY_PRODUCT,
            Product::TYPE_RAW_MATERIAL, Product::TYPE_PACKAGING,
        ], true)) {
            abort(422, 'هذا المنتج غير قابل للتسعير من هنا.');
        }

        if ($product->isBatchPriced()) {
            abort(422, 'هذا المنتج يُسعَّر لكل دفعة توريد على حدة — استخدم شاشة تسعير الدُفعات بدلاً من تعديل سعر واحد للمنتج بالكامل.');
        }

        $isCompound = $product->product_type === Product::TYPE_COMPOUND;
        $pricedByGram = ! $isCompound && $this->isPricedByGram($product);
        $field = $isCompound ? 'default_selling_price' : ($pricedByGram ? 'price_per_gram' : 'selling_price');

        $oldPrice = $product->{$field} !== null ? (float) $product->{$field} : null;

        DB::transaction(function () use ($product, $newPrice, $reason, $admin, $field, $oldPrice) {
            PriceHistory::create([
                'product_id' => $product->id,
                'old_cost' => null, 'new_cost' => null,
                'old_selling_price' => $oldPrice, 'new_selling_price' => round($newPrice, 2),
                'type' => PriceHistory::TYPE_PRICE_EDIT,
                'reason' => $reason,
                'updated_by' => $admin->id,
            ]);

            $product->update([$field => round($newPrice, 2), 'priced_at' => now()]);
        });
    }

    // ── Product pricing detail ────────────────────────────────────────────────

    public function detailFor(Product $product): array
    {
        $row = $this->rowFor($product);

        if ($product->isBatchPriced()) {
            $batchSummary = $this->listBatches($product);
            $row['batches'] = $batchSummary['batches'];
            $row['category_minimum_price'] = $batchSummary['category_minimum_price'];
            $row['category_default_price'] = $batchSummary['category_default_price'];
            return $row;
        }

        $row['latest_purchase_price'] = $this->latestPurchaseCost($product->id);
        $row['average_purchase_price'] = $this->averagePurchaseCost($product->id);

        return $row;
    }

    public function history(Product $product, int $perPage = 20): mixed
    {
        return $product->priceHistories()->with('updatedBy:id,name')->paginate($perPage);
    }
}
