<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplyItem;
use App\Models\User;
use App\Modules\Pricing\Services\PricingService;
use App\Modules\Stock\Services\SupplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lifecycle distinction (first-ever supply vs existing-product new-batch) +
 * grouped/deduplicated Admin notifications — real MySQL test DB, real
 * SupplyService/PricingService/NotificationService calls, no mocks.
 */
class PricingLifecycleNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Supplier $supplier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $productType = ProductType::create([
            'code' => 'qa_ready2', 'name' => 'QA Ready 2', 'is_fixed' => false,
            'sold_by' => 'unit', 'pricing_source' => 'product',
        ]);
        $this->category = Category::create([
            'name' => 'QA Category 2', 'minimum_sell_price' => 0, 'is_fixed' => false,
            'product_type_id' => $productType->id,
        ]);
        $this->supplier = Supplier::create(['name' => 'QA Supplier 2', 'phone' => '01' . random_int(100000000, 999999999)]);
        $this->admin = User::create([
            'name' => 'QA Admin 2', 'email' => 'qa_admin2_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'QA Lifecycle Product ' . uniqid(), 'is_active' => true, 'scalar' => 'pcs',
            'category_id' => $this->category->id, 'product_type' => Product::TYPE_READY_PRODUCT,
            'show_in_catalog' => true,
        ]);
    }

    private function supply(Product $product, float $quantity, float $unitPrice): void
    {
        app(SupplyService::class)->create([
            'supplier_id' => $this->supplier->id, 'payment_method' => 'debt',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ], $this->admin);
    }

    // ── Lifecycle distinction (Scenarios 1–3) ───────────────────────────────

    public function test_first_ever_supply_is_flagged_first_pricing_not_pricing_required(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 100, 50);

        $row = app(PricingService::class)->rowFor($product->fresh());

        $this->assertTrue($row['needs_pricing']);
        $this->assertEquals('first_pricing', $row['pricing_queue']);
    }

    public function test_existing_product_new_supply_is_flagged_pricing_required_and_old_batch_untouched(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 50, 40); // first batch
        $oldItem = SupplyItem::where('product_id', $product->id)->first();
        app(PricingService::class)->priceBatch($product, $oldItem, 120, null, $this->admin);

        $this->supply($product, 100, 60); // second batch, unpriced

        $row = app(PricingService::class)->rowFor($product->fresh());
        $this->assertEquals('pricing_required', $row['pricing_queue']);
        $this->assertEqualsWithDelta(120.0, (float) $oldItem->fresh()->selling_price, 0.01, 'Old batch price must never change.');
    }

    public function test_multiple_new_batches_for_existing_product_appear_independently(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 50, 40);
        $oldItem = SupplyItem::where('product_id', $product->id)->first();
        app(PricingService::class)->priceBatch($product, $oldItem, 120, null, $this->admin);

        $this->supply($product, 100, 60); // new batch A
        $this->supply($product, 70, 62);  // new batch B

        $detail = app(PricingService::class)->listBatches($product->fresh());
        $unpriced = collect($detail['batches'])->where('is_priced', false);
        $this->assertCount(2, $unpriced, 'Both new batches must appear as independent rows.');
    }

    // ── Notifications — grouping, type distinction, deep-link, dedup ───────

    public function test_first_supply_creates_new_product_needs_pricing_notification(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 100, 50);

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'new_product_needs_pricing')->latest('id')->first();

        $this->assertNotNull($notification);
        $this->assertEquals('/dashboard/pricing?filter=first_pricing', $notification->data['route'] ?? null);
        $this->assertContains($product->id, $notification->data['product_ids'] ?? []);
        $this->assertStringContainsString((string) $notification->data['batch_count'], $notification->message);
    }

    public function test_existing_product_new_supply_creates_batches_need_pricing_notification_with_correct_route(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 50, 40);
        $oldItem = SupplyItem::where('product_id', $product->id)->first();
        app(PricingService::class)->priceBatch($product, $oldItem, 120, null, $this->admin);

        Notification::where('user_id', $this->admin->id)->delete(); // isolate the event under test

        $this->supply($product, 100, 60);

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'batches_need_pricing')->latest('id')->first();

        $this->assertNotNull($notification);
        $this->assertEquals('/dashboard/pricing?filter=pricing_required', $notification->data['route'] ?? null);
        // Must NOT also fire a "first pricing" notification for this event — it's not a first-ever supply.
        $this->assertNull(Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->first());
    }

    public function test_duplicate_pending_events_refresh_one_notification_instead_of_stacking(): void
    {
        $p1 = $this->makeProduct();
        $this->supply($p1, 50, 40); // first-ever supply #1

        $count1 = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->count();
        $this->assertEquals(1, $count1);
        $n1 = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->first();
        $this->assertStringContainsString('1', $n1->message);

        $p2 = $this->makeProduct();
        $this->supply($p2, 30, 20); // a second, unrelated first-ever supply, still unresolved

        // Still exactly ONE unread notification of this type — refreshed, not duplicated.
        $countAfter = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->count();
        $this->assertEquals(1, $countAfter, 'Must refresh the existing unread notification, never stack a second one.');

        $refreshed = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->first();
        $this->assertEquals($n1->id, $refreshed->id, 'Same row, updated in place.');
        $this->assertStringContainsString('2', $refreshed->message, 'Count must refresh to the current total (2), not stay at 1.');
    }

    public function test_after_admin_reads_notification_a_new_pending_event_starts_a_fresh_row(): void
    {
        $p1 = $this->makeProduct();
        $this->supply($p1, 50, 40);
        $n1 = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->first();
        $n1->markAsRead();

        $p2 = $this->makeProduct();
        $this->supply($p2, 30, 20);

        $unread = Notification::where('user_id', $this->admin->id)->where('type', 'new_product_needs_pricing')->whereNull('read_at')->get();
        $this->assertCount(1, $unread, 'A fresh row starts once the previous one was read — not merged into the read one.');
        $this->assertNotEquals($n1->id, $unread->first()->id);
    }

    public function test_pending_pricing_summary_reflects_real_current_state(): void
    {
        $p1 = $this->makeProduct();
        $this->supply($p1, 50, 40); // unpriced, first-ever

        $p2 = $this->makeProduct();
        $this->supply($p2, 20, 30);
        $p2Item = SupplyItem::where('product_id', $p2->id)->first();
        app(PricingService::class)->priceBatch($p2, $p2Item, 100, null, $this->admin);
        $this->supply($p2, 40, 35); // new unpriced batch on an existing product

        $summary = app(PricingService::class)->pendingPricingSummary();

        $this->assertGreaterThanOrEqual(1, $summary['first_pricing']['count']);
        $this->assertContains($p1->id, $summary['first_pricing']['product_ids']);
        $this->assertGreaterThanOrEqual(1, $summary['pricing_required']['count']);
        $this->assertContains($p2->id, $summary['pricing_required']['product_ids']);
        // Cross-check: p1 must never appear in the wrong bucket, and vice versa.
        $this->assertNotContains($p1->id, $summary['pricing_required']['product_ids']);
        $this->assertNotContains($p2->id, $summary['first_pricing']['product_ids']);
    }

    public function test_queue_empties_after_pricing_and_first_pricing_leaves_the_queue(): void
    {
        $product = $this->makeProduct();
        $this->supply($product, 100, 50);
        $item = SupplyItem::where('product_id', $product->id)->first();

        app(PricingService::class)->priceBatch($product, $item, 180, null, $this->admin);

        $row = app(PricingService::class)->rowFor($product->fresh());
        $this->assertFalse($row['needs_pricing']);
        $this->assertNull($row['pricing_queue'], 'Product must leave the queue entirely once its only batch is priced.');
    }
}
