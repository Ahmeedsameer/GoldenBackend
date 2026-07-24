<?php

namespace App\Modules\Pricing\Services;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\User;
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
            ->with('category.productType:id,pricing_source')
            ->search($search)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'scalar', 'product_type', 'category_id', 'selling_price', 'price_per_gram', 'default_selling_price', 'priced_cost', 'priced_at']);

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

    // ── "Update Prices" — Ready Products only, admin-triggered, cost-only ────

    /** Products whose live purchase cost differs from the cost Pricing is currently using. */
    public function previewPriceUpdate(): array
    {
        $products = Product::query()
            ->where('product_type', Product::TYPE_READY_PRODUCT)
            ->get(['id', 'name', 'sku', 'selling_price', 'priced_cost']);

        $changes = [];
        foreach ($products as $product) {
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
        $row['latest_purchase_price'] = $this->latestPurchaseCost($product->id);
        $row['average_purchase_price'] = $this->averagePurchaseCost($product->id);

        return $row;
    }

    public function history(Product $product, int $perPage = 20): mixed
    {
        return $product->priceHistories()->with('updatedBy:id,name')->paginate($perPage);
    }
}
