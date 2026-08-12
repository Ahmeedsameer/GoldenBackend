<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Goods;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Safe;
use App\Models\SafeType;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\User;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Modules\Pricing\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final QA gap closure — end-to-end verification via the REAL HTTP → Controller
 * → Service → DB path (no mocks), against a real MySQL test database.
 * RefreshDatabase wraps each test in a transaction that's rolled back at the
 * end, so every disposable record created here is automatically cleaned up —
 * no manual teardown needed and nothing leaks into any other database.
 */
class BatchPricingQaTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;
    private Shop $shopB;
    private Category $category;
    private Supplier $supplier;
    private User $admin;
    private User $seller;
    private Safe $safeA;
    private Currency $egp;
    private PaymentMethod $cash;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = Shop::create(['name' => 'Shop A', 'address' => 'x', 'status' => 'active']);
        $this->shopB = Shop::create(['name' => 'Shop B', 'address' => 'x', 'status' => 'active']);

        $productType = ProductType::create([
            'code' => 'qa_ready', 'name' => 'QA Ready', 'is_fixed' => false,
            'sold_by' => 'unit', 'pricing_source' => 'product',
        ]);
        $this->category = Category::create([
            'name' => 'QA Category', 'minimum_sell_price' => 0, 'is_fixed' => false,
            'product_type_id' => $productType->id,
        ]);
        $this->supplier = Supplier::create(['name' => 'QA Supplier', 'phone' => '01' . random_int(100000000, 999999999)]);

        $this->admin = User::create([
            'name' => 'QA Admin', 'email' => 'qa_admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->seller = User::create([
            'name' => 'QA Seller', 'email' => 'qa_seller_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'active', 'shop_id' => $this->shopA->id,
        ]);
        // Shift Lock precondition: a published all-day WORK shift so this
        // pre-existing FIFO/catalog QA (unrelated to shift restrictions)
        // keeps selling via the real POS endpoint under the new shift-based
        // selling-access rule.
        ScheduleEntry::create([
            'user_id' => $this->seller->id, 'date' => today()->toDateString(), 'type' => ScheduleEntry::WORK,
            'start_time' => '00:00', 'end_time' => '23:59', 'shop_id' => $this->shopA->id,
            'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        $safeType = SafeType::first() ?? SafeType::create(['name' => 'Cash', 'kind' => 'physical', 'is_active' => true]);
        $this->safeA = Safe::create(['shop_id' => $this->shopA->id, 'safe_type_id' => $safeType->id, 'is_active' => true]);
        Safe::create(['shop_id' => $this->shopB->id, 'safe_type_id' => $safeType->id, 'is_active' => true]);

        $this->egp = Currency::where('code', 'EGP')->first();
        $this->cash = PaymentMethod::where('type', 'cash')->first();

        $this->product = Product::create([
            'name' => 'QA Product', 'is_active' => true, 'scalar' => 'pcs',
            'category_id' => $this->category->id, 'product_type' => Product::TYPE_READY_PRODUCT,
            'show_in_catalog' => true,
        ]);
    }

    /** @return array{0: Supply, 1: SupplyItem, 2: Goods} */
    private function makeBatch(Shop $shop, ?float $sellingPrice, float $quantity, ?\DateTimeInterface $date = null): array
    {
        $supply = Supply::create([
            'supplier_id' => $this->supplier->id,
            'date' => ($date ?? now())->format('Y-m-d'),
            'payment_method' => 'debt',
        ]);
        $item = SupplyItem::create([
            'supply_id' => $supply->id, 'product_id' => $this->product->id,
            'quantity' => $quantity, 'unit_price' => 50,
            'selling_price' => $sellingPrice, 'priced_at' => $sellingPrice !== null ? now() : null,
        ]);
        $goods = Goods::create([
            'supply_item_id' => $item->id, 'shop_id' => $shop->id,
            'current_quantity' => $quantity, 'date' => ($date ?? now())->format('Y-m-d'),
        ]);

        return [$supply, $item, $goods];
    }

    private function catalogPrice(User $as, Shop $shop): ?array
    {
        $response = $this->actingAs($as, 'api')->getJson('/api/sales/catalog-products');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->product->id);

        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. End-to-end FIFO split invoice
    // ─────────────────────────────────────────────────────────────────────

    public function test_fifo_split_invoice_end_to_end_via_real_http(): void
    {
        [, $oldItem, $oldGoods] = $this->makeBatch($this->shopA, 120, 50, now()->subDays(2));
        [, $newItem, $newGoods] = $this->makeBatch($this->shopA, 180, 100, now());

        $response = $this->actingAs($this->seller, 'api')->postJson('/api/sales/invoices', [
            'price_type' => 'retail',
            'total_amount' => 11400.00,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 80],
            ],
            'payments' => [
                ['currency_id' => $this->egp->id, 'amount' => 11400.00, 'payment_method_id' => $this->cash->id],
            ],
        ]);

        $response->assertCreated();
        $invoiceId = $response->json('data.id') ?? $response->json('data.invoice.id');
        $this->assertNotNull($invoiceId, 'Response: ' . $response->content());

        $invoice = Invoice::with('items')->findOrFail($invoiceId);

        // Invoice total
        $this->assertEqualsWithDelta(11400.00, (float) $invoice->total_amount, 0.01);

        // Invoice lines — exactly two, split by batch, never averaged.
        $lines = $invoice->items->sortBy('price')->values();
        $this->assertCount(2, $lines, 'FIFO must create one InvoiceItem per batch consumed, not one averaged line.');

        $oldLine = $lines->firstWhere('supply_item_id', $oldItem->id);
        $newLine = $lines->firstWhere('supply_item_id', $newItem->id);
        $this->assertNotNull($oldLine);
        $this->assertNotNull($newLine);

        $this->assertEqualsWithDelta(50.0, (float) $oldLine->quantity, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $oldLine->price, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $newLine->quantity, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $newLine->price, 0.01);

        $this->assertEqualsWithDelta(6000.0, (float) $oldLine->quantity * (float) $oldLine->price, 0.01);
        $this->assertEqualsWithDelta(5400.0, (float) $newLine->quantity * (float) $newLine->price, 0.01);
        $this->assertEqualsWithDelta(11400.0, $lines->sum(fn ($l) => $l->quantity * $l->price), 0.01);

        // No average — neither line price is anywhere near (120+180)/2=150.
        $this->assertNotEqualsWithDelta(150.0, (float) $oldLine->price, 0.01);
        $this->assertNotEqualsWithDelta(150.0, (float) $newLine->price, 0.01);

        // Remaining batch quantities in the DB.
        $this->assertEqualsWithDelta(0.0, (float) $oldGoods->fresh()->current_quantity, 0.001, 'Old batch fully consumed.');
        $this->assertEqualsWithDelta(70.0, (float) $newGoods->fresh()->current_quantity, 0.001, 'New batch: 100 - 30 = 70 left.');

        // Profit — unit_cost frozen from each batch's own unit_price (50).
        $this->assertEqualsWithDelta(50.0, (float) $oldLine->unit_cost, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $newLine->unit_cost, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Catalog + inventory event verification (A–G chained on one product)
    // ─────────────────────────────────────────────────────────────────────

    public function test_catalog_reacts_correctly_across_the_full_inventory_event_chain(): void
    {
        // A. New Supply — old priced batch only so far.
        [, $oldItem, $oldGoods] = $this->makeBatch($this->shopA, 120, 50, now()->subDays(2));
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNotNull($row, 'Product visible with priced stock.');
        $this->assertEquals(120.0, $row['configured_unit_price']);

        // Now a second, unpriced supply arrives (event A continued).
        [, $newItem, $newGoods] = $this->makeBatch($this->shopA, null, 100, now());
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNotNull($row, 'Still visible — old priced batch still sellable.');
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Unpriced new batch must never surface as the sellable price.');

        // B. Batch Pricing — admin prices the new batch via the real bulk endpoint.
        $priceResponse = $this->actingAs($this->admin, 'api')->postJson('/api/pricing/batches/bulk-price', [
            'items' => [
                ['supply_item_id' => $newItem->id, 'selling_price' => 180, 'reason' => 'QA'],
            ],
        ]);
        $priceResponse->assertOk();
        $this->assertTrue($priceResponse->json('data.0.success'));
        // Old batch is still oldest/sellable, so catalog price is unchanged for now.
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Old batch still first in FIFO line — pricing the new batch alone does not jump the price yet.');

        // C. Sale — consume the old batch completely via a real invoice.
        $saleResponse = $this->actingAs($this->seller, 'api')->postJson('/api/sales/invoices', [
            'price_type' => 'retail',
            'total_amount' => 6000.00,
            'items' => [['product_id' => $this->product->id, 'quantity' => 50]],
            'payments' => [['currency_id' => $this->egp->id, 'amount' => 6000.00, 'payment_method_id' => $this->cash->id]],
        ]);
        $saleResponse->assertCreated();
        $invoiceId = $saleResponse->json('data.id') ?? $saleResponse->json('data.invoice.id');

        $this->assertEqualsWithDelta(0.0, (float) $oldGoods->fresh()->current_quantity, 0.001);
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertEquals(180.0, $row['configured_unit_price'], 'Old batch exhausted — price must move to the new batch, from 120 to 180.');

        // D. Sale Cancellation — old batch quantity restored, price returns to 120.
        $cancelResponse = $this->actingAs($this->admin, 'api')->postJson("/api/admin/invoices/{$invoiceId}/cancel", [
            'reason' => 'QA rollback',
        ]);
        $cancelResponse->assertOk();

        $this->assertEqualsWithDelta(50.0, (float) $oldGoods->fresh()->current_quantity, 0.001, 'Cancellation must restore the exact original batch quantity.');
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Display price must return to the old batch — no stale cached price.');

        // E. Transfer — move 20 units of the (now-restored) old batch from Shop A to Shop B.
        $transferResponse = $this->actingAs($this->admin, 'api')->postJson('/api/branch-operations/transfers', [
            'source_shop_id' => $this->shopA->id,
            'destination_shop_id' => $this->shopB->id,
            'submit' => true,
            'items' => [
                ['product_id' => $this->product->id, 'requested_quantity' => 20],
            ],
        ]);
        $transferResponse->assertCreated();
        $transferId = $transferResponse->json('data.id');
        $itemId = \App\Modules\BranchOperations\Models\TransferRequestItem::where('transfer_request_id', $transferId)->value('id');
        $this->assertNotNull($itemId, 'Transfer response: ' . $transferResponse->content());
        $this->assertEqualsWithDelta(30.0, (float) $oldGoods->fresh()->current_quantity, 0.001, 'Shipping decrements the source batch (50 - 20 = 30).');

        $receiveResponse = $this->actingAs($this->admin, 'api')->postJson("/api/branch-operations/transfers/{$transferId}/receive", [
            'items' => [
                ['item_id' => $itemId, 'received_quantity' => 20],
            ],
        ]);
        $receiveResponse->assertOk();

        // Shop A's own catalog state must still be correct after the transfer.
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNotNull($row);
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Old batch still has stock in Shop A — price unchanged by the transfer.');

        // F. Inventory Adjustment — Shop A currently holds old(30 @120) + new(100 @180) = 130
        // total. Correcting down to 25 is a 105-unit shortfall: FIFO-consumes the old batch
        // entirely (30) first, then dips into the new batch for the remaining 75, leaving
        // new = 25. So after this, only the 180 batch has stock left — same FIFO rule the
        // adjustment shortfall path is documented to follow.
        $adjustResponse = $this->actingAs($this->admin, 'api')->postJson('/api/branch-operations/adjustments', [
            'shop_id' => $this->shopA->id,
            'product_id' => $this->product->id,
            'after_quantity' => 25,
            'reason' => 'QA count correction',
        ]);
        $adjustResponse->assertCreated();
        $adjustId = $adjustResponse->json('data.id');

        $this->actingAs($this->admin, 'api')->postJson("/api/branch-operations/adjustments/{$adjustId}/approve")->assertOk();
        $this->actingAs($this->admin, 'api')->postJson("/api/branch-operations/adjustments/{$adjustId}/execute")->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) $oldGoods->fresh()->current_quantity, 0.001, 'FIFO shortfall consumes the old batch first.');
        $this->assertEqualsWithDelta(25.0, (float) $newGoods->fresh()->current_quantity, 0.001, 'Remaining shortfall dips into the new batch.');
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNotNull($row, 'New batch still has 25 units left.');
        $this->assertEquals(180.0, $row['configured_unit_price'], 'Old batch exhausted by the adjustment — price must move to the new batch.');

        // G. Waste — register waste against ALL remaining stock (the new 180 batch).
        $wasteReason = \App\Modules\BranchOperations\Models\WasteRecord::REASONS[0] ?? 'damaged';
        $wasteResponse = $this->actingAs($this->admin, 'api')->postJson('/api/branch-operations/waste', [
            'shop_id' => $this->shopA->id,
            'product_id' => $this->product->id,
            'quantity' => 25,
            'reason' => $wasteReason,
        ]);
        $wasteResponse->assertCreated();

        $this->assertEqualsWithDelta(0.0, (float) $newGoods->fresh()->current_quantity, 0.001);

        // No sellable stock left anywhere in Shop A — the product must disappear
        // from the catalog entirely, never show a stale/null price as "sellable".
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNull($row, 'Zero sellable stock left — product must be excluded from the catalog, not shown with a stale/null price.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Notification verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_new_supply_notifies_admin_without_blocking_older_priced_stock(): void
    {
        [, , $oldGoods] = $this->makeBatch($this->shopA, 120, 50, now()->subDays(2));

        $before = Notification::where('user_id', $this->admin->id)->where('type', 'batches_need_pricing')->count();

        // Real supply-creation path (SupplyService::create), not direct model inserts,
        // so the actual notification-triggering code runs.
        $supplyService = app(\App\Modules\Stock\Services\SupplyService::class);
        $supplyService->create([
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'debt',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 100, 'unit_price' => 50],
            ],
        ], $this->admin);

        $after = Notification::where('user_id', $this->admin->id)->where('type', 'batches_need_pricing')->count();
        $this->assertEquals($before + 1, $after, 'Exactly one new notification for this supply — no duplicates.');

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'batches_need_pricing')->latest('id')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('QA Product', $notification->message);
        $this->assertEquals('/dashboard/pricing?filter=pricing_required', $notification->data['route'] ?? null, 'Must point to Pricing Management with the correct queue filter.');
        $this->assertContains($this->product->id, $notification->data['product_ids'] ?? []);

        // The new unpriced batch must not be sellable, and must not block the old priced batch.
        $row = $this->catalogPrice($this->seller, $this->shopA);
        $this->assertNotNull($row);
        $this->assertEquals(120.0, $row['configured_unit_price'], 'Old priced batch still sellable — the new unpriced batch does not block it.');
        $this->assertEqualsWithDelta(50.0, (float) $oldGoods->fresh()->current_quantity, 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Bulk pricing verification (via real HTTP)
    // ─────────────────────────────────────────────────────────────────────

    public function test_bulk_pricing_via_http_is_independent_per_batch_and_reports_per_item(): void
    {
        $this->category->update(['minimum_sell_price' => 100]);

        [, $item1] = $this->makeBatch($this->shopA, null, 10);
        [, $item2] = $this->makeBatch($this->shopA, null, 10);
        [, $itemAlreadyPriced] = $this->makeBatch($this->shopA, 999, 10);
        [, $itemBelowMinimum] = $this->makeBatch($this->shopA, null, 10);

        $response = $this->actingAs($this->admin, 'api')->postJson('/api/pricing/batches/bulk-price', [
            'items' => [
                ['supply_item_id' => $item1->id, 'selling_price' => 180, 'reason' => 'New supply'],
                ['supply_item_id' => $item2->id, 'selling_price' => 250, 'reason' => 'New supply'],
                ['supply_item_id' => $itemAlreadyPriced->id, 'selling_price' => 500], // immutable — must fail
                ['supply_item_id' => $itemBelowMinimum->id, 'selling_price' => 50],   // below category minimum — must fail
            ],
        ]);

        $response->assertOk();
        $results = $response->json('data');
        $this->assertCount(4, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
        $this->assertFalse($results[2]['success'], 'Already-priced immutable batch must be protected.');
        $this->assertFalse($results[3]['success'], 'Category minimum must still be enforced inside bulk pricing.');

        // Each batch got its OWN price — never one shared price applied to all.
        $this->assertEqualsWithDelta(180.0, (float) $item1->fresh()->selling_price, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $item2->fresh()->selling_price, 0.01);
        $this->assertNotEquals((float) $item1->fresh()->selling_price, (float) $item2->fresh()->selling_price);

        // The invalid items must NOT have overwritten existing/protected pricing.
        $this->assertEqualsWithDelta(999.0, (float) $itemAlreadyPriced->fresh()->selling_price, 0.01);
        $this->assertNull($itemBelowMinimum->fresh()->selling_price);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Historical invoice protection (re-verified after everything above)
    // ─────────────────────────────────────────────────────────────────────

    public function test_historical_invoice_protection_after_batch_price_edit(): void
    {
        [, $item, $goods] = $this->makeBatch($this->shopA, 120, 50);

        $invoice = Invoice::create([
            'shop_id' => $this->shopA->id, 'seller_id' => $this->seller->id,
            'date' => now()->format('Y-m-d'), 'price_type' => 'retail',
            'status' => 'approved', 'total_amount' => 600,
        ]);
        $invoiceItem = \App\Models\InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $this->product->id,
            'goods_id' => $goods->id, 'supply_item_id' => $item->id,
            'quantity' => 5, 'price' => 120, 'unit_cost' => 50,
        ]);
        $frozenLineCost = (float) $invoiceItem->line_cost;
        $frozenLineProfit = (float) $invoiceItem->line_profit;

        app(PricingService::class)->updateBatchPrice($this->product, $item, 200, 'QA price correction', $this->admin);

        $invoiceItem->refresh();
        $invoice->refresh();
        $this->assertEqualsWithDelta(120.0, (float) $invoiceItem->price, 0.01, 'InvoiceItem price must stay frozen.');
        $this->assertEqualsWithDelta(600.0, (float) $invoice->total_amount, 0.01, 'Invoice total must be unaffected.');
        $this->assertEqualsWithDelta($frozenLineCost, (float) $invoiceItem->line_cost, 0.01);
        $this->assertEqualsWithDelta($frozenLineProfit, (float) $invoiceItem->line_profit, 0.01, 'Profit must stay frozen.');
        $this->assertEquals($item->id, $invoiceItem->supply_item_id, 'Historical batch reference unchanged.');
    }
}
