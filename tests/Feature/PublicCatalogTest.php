<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real-MySQL verification of the public (unauthenticated) Landing Page
 * catalog endpoint — no auth required, only active + catalog-visible
 * products returned, and never leaks cost/profit/inventory fields.
 */
class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $type = ProductType::create(['code' => 'qa_' . uniqid(), 'name' => 'QA Type', 'is_fixed' => false, 'sold_by' => 'unit', 'pricing_source' => 'product']);
        $category = Category::create(['name' => 'QA Category', 'minimum_sell_price' => 0, 'is_fixed' => false, 'product_type_id' => $type->id]);

        return Product::create(array_merge([
            'name' => 'QA Perfume ' . uniqid(),
            'is_active' => true,
            'scalar' => 'pcs',
            'category_id' => $category->id,
            'product_type' => Product::TYPE_READY_PRODUCT,
            'show_in_catalog' => true,
            'selling_price' => 450,
            'purchase_cost' => 200,
            'warning_quantity' => 5,
            'critical_quantity' => 2,
            'description' => 'عطر فاخر',
        ], $overrides));
    }

    public function test_endpoint_requires_no_authentication(): void
    {
        $this->makeProduct();

        $response = $this->getJson('/api/public/catalog');

        $response->assertOk();
    }

    public function test_only_active_and_catalog_visible_products_are_returned(): void
    {
        $visible = $this->makeProduct(['name' => 'Visible Perfume']);
        $hiddenFromCatalog = $this->makeProduct(['name' => 'Hidden Perfume', 'show_in_catalog' => false]);
        $inactive = $this->makeProduct(['name' => 'Inactive Perfume', 'is_active' => false]);

        $response = $this->getJson('/api/public/catalog');

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Visible Perfume', $names);
        $this->assertNotContains('Hidden Perfume', $names);
        $this->assertNotContains('Inactive Perfume', $names);
    }

    public function test_response_never_leaks_cost_profit_or_inventory_fields(): void
    {
        $this->makeProduct();

        $response = $this->getJson('/api/public/catalog');

        $row = $response->json('data.0');
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('price', $row);
        $this->assertArrayNotHasKey('purchase_cost', $row);
        $this->assertArrayNotHasKey('profit', $row);
        $this->assertArrayNotHasKey('warning_quantity', $row);
        $this->assertArrayNotHasKey('critical_quantity', $row);
        $this->assertArrayNotHasKey('sku', $row);
        $this->assertArrayNotHasKey('barcode', $row);
    }

    public function test_price_field_reflects_the_products_actual_selling_price(): void
    {
        $this->makeProduct(['selling_price' => 599]);

        $response = $this->getJson('/api/public/catalog');

        $this->assertEqualsWithDelta(599.0, (float) $response->json('data.0.price'), 0.01);
    }
}
