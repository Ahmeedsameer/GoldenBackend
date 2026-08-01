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
     * Backfills every EXISTING SupplyItem (batch) and InvoiceItem so nothing
     * regresses to "unpriced"/"unknown cost" the moment the new columns
     * appear. Every batch-priced product that was already sellable today
     * (has a selling_price/price_per_gram configured) gets ALL of its
     * existing batches marked as already-priced at that same price — exactly
     * what was actually charged for that stock historically, so this is not
     * a fabricated number, just making the existing single product-level
     * price explicit per batch. Existing invoice items get their cost
     * snapshot filled from the same live join the app already used, frozen
     * from this point on. No existing `products`, `supplies`, `invoices`, or
     * `invoice_items` business column is altered — only the new nullable
     * columns are populated.
     */
    public function up(): void
    {
        $now = now();
        // price_histories.updated_by is NOT NULL — legacy rows have no real
        // actor, so attribute the migration itself to the first admin account.
        $systemActorId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        Product::query()
            ->whereHas('category.productType', fn ($q) => $q->where('pricing_source', ProductType::PRICING_SOURCE_PRODUCT))
            ->where('product_type', '!=', Product::TYPE_COMPOUND)
            ->with('category.productType')
            ->chunkById(100, function ($products) use ($now, $systemActorId) {
                foreach ($products as $product) {
                    $legacyPrice = $product->selling_price ?? $product->price_per_gram;
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
                            'reason'            => 'ترحيل تلقائي من نظام التسعير القديم (سعر واحد لكل منتج) عند تفعيل التسعير باللوط',
                            'updated_by'        => $product->priceHistories()->latest()->value('updated_by') ?? $systemActorId,
                        ]);
                    }
                }
            });

        // Freeze the cost snapshot on every existing invoice item that can
        // resolve one — same figure the live join already returned, just no
        // longer dependent on that join remaining resolvable forever.
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
        // Intentionally left as a no-op: reversing would mean deleting the
        // migration-generated PriceHistory rows and re-nulling real batch
        // prices/invoice cost snapshots, which is itself a destructive,
        // history-altering operation the whole point of this feature forbids.
    }
};
