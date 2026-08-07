<?php

use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\SupplyItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Product::isBatchPriced() was just widened to cover oil/category-priced
     * Raw Materials too (previously only Ready Products/Packaging). Mirrors
     * 2026_08_02_000004_backfill_batch_pricing_for_existing_data.php exactly,
     * for the one product_type that migration deliberately excluded
     * (pricing_source = 'category'): every existing un-priced oil SupplyItem
     * gets its selling_price backfilled from the product's own (legacy)
     * price_per_gram — falling back to the category's price_per_gram when the
     * product itself was never individually priced — so no existing oil batch
     * silently becomes unsellable the moment batch pricing applies to it. Not
     * a fabricated number: it's the same price this stock was already selling at.
     */
    public function up(): void
    {
        $now = now();
        $systemActorId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        Product::query()
            ->whereHas('category.productType', fn ($q) => $q->where('pricing_source', ProductType::PRICING_SOURCE_CATEGORY))
            ->where('product_type', Product::TYPE_RAW_MATERIAL)
            ->with('category')
            ->chunkById(100, function ($products) use ($now, $systemActorId) {
                foreach ($products as $product) {
                    $legacyPrice = $product->price_per_gram ?? $product->category?->price_per_gram;
                    if ($legacyPrice === null) {
                        continue; // was never sellable before either — stays unpriced, correctly
                    }

                    $unpriced = SupplyItem::where('product_id', $product->id)->whereNull('selling_price')->get();
                    foreach ($unpriced as $batch) {
                        $batch->update([
                            'selling_price' => $legacyPrice,
                            'priced_at'     => $product->priced_at ?? $now,
                            'priced_by'     => null, // legacy — no specific actor recorded historically
                        ]);

                        PriceHistory::create([
                            'product_id'        => $product->id,
                            'supply_item_id'    => $batch->id,
                            'old_selling_price' => null,
                            'new_selling_price' => $legacyPrice,
                            'type'              => PriceHistory::TYPE_BATCH_PRICING,
                            'reason'            => 'ترحيل تلقائي من نظام التسعير القديم (سعر الجرام على مستوى المنتج/الفئة) عند تفعيل التسعير باللوط للمواد الخام',
                            'updated_by'        => $systemActorId,
                        ]);
                    }
                }
            });

        // Freeze the cost snapshot on any oil invoice item that can resolve
        // one and hadn't been backfilled yet (same join the 2026-08-02
        // migration already ran for non-oil products).
        DB::statement("
            UPDATE invoice_items ii
            INNER JOIN goods g ON g.id = ii.goods_id
            INNER JOIN supply_items si ON si.id = g.supply_item_id
            SET ii.unit_cost = si.unit_price
            WHERE ii.unit_cost IS NULL
        ");
    }

    public function down(): void
    {
        // Intentionally a no-op — see 2026_08_02_000004's identical rationale:
        // reversing would mean deleting real PriceHistory rows and re-nulling
        // real batch prices/invoice cost snapshots.
    }
};
