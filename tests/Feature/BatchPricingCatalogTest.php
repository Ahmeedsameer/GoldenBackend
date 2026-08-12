<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Goods;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\User;
use App\Modules\Pricing\Services\PricingService;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business-rules coverage for "Batch Pricing, Supply, Sales Catalog & Selling
 * Price": exercises the real SalesService/PricingService classes against a
 * real (in-memory sqlite) database — no mocks — matching the QA requirement
 * to test against real application/database paths.
 */
class BatchPricingCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Category $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Test Shop', 'address' => 'x', 'status' => 'active']);

        $productType = ProductType::create([
            'code' => 'test_ready', 'name' => 'Test Ready', 'is_fixed' => false,
            'sold_by' => 'unit', 'pricing_source' => 'product',
        ]);

        $this->category = Category::create([
            'name' => 'Test Category', 'minimum_sell_price' => 0, 'is_fixed' => false,
            'product_type_id' => $productType->id,
        ]);

        $this->supplier = Supplier::create(['name' => 'Test Supplier', 'phone' => '0100' . random_int(1000000, 9999999)]);
    }

    private function makeReadyProduct(): Product
    {
        return Product::create([
            'name' => 'Test Product ' . uniqid(),
            'is_active' => true,
            'scalar' => 'pcs',
            'category_id' => $this->category->id,
            'product_type' => Product::TYPE_READY_PRODUCT,
            'show_in_catalog' => true,
        ]);
    }

    /** @return array{0: Supply, 1: SupplyItem, 2: Goods} */
    private function makeBatch(Product $product, ?float $sellingPrice, float $quantity, ?\DateTimeInterface $date = null): array
    {
        $supply = Supply::create([
            'supplier_id' => $this->supplier->id,
            'date' => ($date ?? now())->format('Y-m-d'),
            'payment_method' => 'debt',
        ]);

        $item = SupplyItem::create([
            'supply_id' => $supply->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 50,
            'selling_price' => $sellingPrice,
            'priced_at' => $sellingPrice !== null ? now() : null,
        ]);

        $goods = Goods::create([
            'supply_item_id' => $item->id,
            'shop_id' => $this->shop->id,
            'current_quantity' => $quantity,
            'date' => ($date ?? now())->format('Y-m-d'),
        ]);

        return [$supply, $item, $goods];
    }

    /** Scenario 1 — a product that has never been supplied must not appear in the catalog. */
    public function test_never_supplied_product_is_excluded_from_catalog(): void
    {
        $product = $this->makeReadyProduct();

        $catalog = app(SalesService::class)->searchCatalogProducts($this->shop->id, null, 50);

        $this->assertFalse(collect($catalog)->contains('id', $product->id));
    }

    /** A product whose only batch has no selling_price yet must also be excluded. */
    public function test_product_with_only_unpriced_batch_is_excluded_from_catalog(): void
    {
        $product = $this->makeReadyProduct();
        $this->makeBatch($product, null, 100);

        $catalog = app(SalesService::class)->searchCatalogProducts($this->shop->id, null, 50);

        $this->assertFalse(collect($catalog)->contains('id', $product->id));
    }

    /** Scenario 2 — an existing priced batch is sellable and appears with its own price. */
    public function test_product_with_priced_batch_appears_in_catalog_with_correct_price(): void
    {
        $product = $this->makeReadyProduct();
        $this->makeBatch($product, 120, 50);

        $catalog = collect(app(SalesService::class)->searchCatalogProducts($this->shop->id, null, 50));
        $row = $catalog->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertEquals(120.0, $row['configured_unit_price']);
        $this->assertEquals(50.0, $row['shop_stock']);
    }

    /** Scenario 3/15 — old priced batch + new unpriced batch: product stays visible
     *  at the OLD batch's price; the unpriced batch never contributes a price. */
    public function test_old_priced_plus_new_unpriced_batch_uses_old_price_and_stays_visible(): void
    {
        $product = $this->makeReadyProduct();
        $this->makeBatch($product, 120, 50, now()->subDays(2));
        $this->makeBatch($product, null, 100, now());

        $catalog = collect(app(SalesService::class)->searchCatalogProducts($this->shop->id, null, 50));
        $row = $catalog->firstWhere('id', $product->id);

        $this->assertNotNull($row, 'Product must remain visible while an old priced batch is sellable.');
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Must use the old batch price, never null/averaged.');
    }

    /** Scenario 9/14 — once the old batch is exhausted, the display price must
     *  move to the next batch's price, never keep showing the old one. */
    public function test_display_price_switches_to_new_batch_once_old_batch_exhausted(): void
    {
        $product = $this->makeReadyProduct();
        [, $oldItem] = $this->makeBatch($product, 120, 50, now()->subDays(2));
        $this->makeBatch($product, 180, 100, now());

        // Exhaust the old batch (simulates it being fully sold/consumed).
        Goods::where('supply_item_id', $oldItem->id)->update(['current_quantity' => 0]);

        $catalog = collect(app(SalesService::class)->searchCatalogProducts($this->shop->id, null, 50));
        $row = $catalog->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertEquals(180.0, $row['configured_unit_price'], 'Price must move to the new batch, never stay at the exhausted old price.');
    }

    /** Scenario 6/19 — bulk pricing prices each batch independently; a shared
     *  price is never applied, and one bad item does not abort the others. */
    public function test_bulk_price_batches_applies_independent_prices_and_isolates_failures(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $p1 = $this->makeReadyProduct();
        $p2 = $this->makeReadyProduct();
        $p3 = $this->makeReadyProduct();

        [, $item1] = $this->makeBatch($p1, null, 10);
        [, $item2] = $this->makeBatch($p2, null, 10);
        [, $item3AlreadyPriced] = $this->makeBatch($p3, 999, 10); // already priced — must fail independently

        $results = app(PricingService::class)->bulkPriceBatches([
            ['supply_item_id' => $item1->id, 'selling_price' => 120],
            ['supply_item_id' => $item2->id, 'selling_price' => 180],
            ['supply_item_id' => $item3AlreadyPriced->id, 'selling_price' => 200],
        ], $admin);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
        $this->assertFalse($results[2]['success'], 'Already-priced batch must fail, not silently succeed.');

        $this->assertEquals(120.0, (float) $item1->fresh()->selling_price);
        $this->assertEquals(180.0, (float) $item2->fresh()->selling_price, 'Each batch keeps its OWN price — never one shared price.');
        $this->assertEquals(999.0, (float) $item3AlreadyPriced->fresh()->selling_price, 'Untouched — the failed item must not have been mutated.');
    }

    /** Scenario 8 — category minimum still enforced per item inside a bulk request. */
    public function test_bulk_price_batches_enforces_category_minimum_per_item(): void
    {
        $this->category->update(['minimum_sell_price' => 100]);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $product = $this->makeReadyProduct();
        [, $item] = $this->makeBatch($product, null, 10);

        $results = app(PricingService::class)->bulkPriceBatches([
            ['supply_item_id' => $item->id, 'selling_price' => 50], // below the 100 minimum
        ], $admin);

        $this->assertFalse($results[0]['success']);
        $this->assertNull($item->fresh()->selling_price, 'Price must not be set when below the category minimum.');
    }

    /** Scenario 7 — editing a batch's price must never touch a historical
     *  InvoiceItem's frozen price snapshot. Verified at the model layer since
     *  InvoiceItem rows are immutable-by-convention once created. */
    public function test_editing_batch_price_does_not_touch_frozen_invoice_item_snapshot(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $product = $this->makeReadyProduct();
        [, $item, $goods] = $this->makeBatch($product, 120, 50);

        $invoice = \App\Models\Invoice::create([
            'shop_id' => $this->shop->id,
            'seller_id' => $admin->id,
            'date' => now()->format('Y-m-d'),
            'price_type' => 'retail',
            'status' => 'approved',
            'total_amount' => 600,
        ]);

        $invoiceItem = \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'goods_id' => $goods->id,
            'supply_item_id' => $item->id,
            'quantity' => 5,
            'price' => 120, // frozen snapshot taken at sale time
            'unit_cost' => 50,
        ]);

        app(PricingService::class)->updateBatchPrice($product, $item, 180, 'price correction', $admin);

        $this->assertEquals(180.0, (float) $item->fresh()->selling_price, 'Future price updated.');
        $this->assertEquals(120.0, (float) $invoiceItem->fresh()->price, 'Historical InvoiceItem price must stay frozen.');
    }
}
