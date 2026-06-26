<?php

namespace Database\Seeders;

use App\Models\Goods;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyItem;
use Illuminate\Database\Seeder;

class SupplySeeder extends Seeder
{
    /**
     * Creates 4 supply orders that stock both shops with realistic
     * perfume-company inventory so sellers can make sales immediately.
     *
     * Unit costs reflect real perfume trade:
     *   - Raw fragrance oils:  priced per ml (e.g. oud oil = 25 EGP/ml)
     *   - Oud/incense:         priced per gram (e.g. raw oud = 80–120 EGP/g)
     *   - Carrier bases:       priced per ml (cheap, e.g. DPG = 0.40 EGP/ml)
     *   - Ready-made perfumes: priced per bottle (wholesale cost)
     *   - Bottles/packaging:   priced per piece
     */
    public function run(): void
    {
        $shop1 = Shop::where('name', 'الفرع الرئيسي')->first();
        $shop2 = Shop::where('name', 'فرع الشمال')->first();

        if (! $shop1 || ! $shop2) {
            $this->command->warn('Shops not found — run ShopSeeder first.');
            return;
        }

        // Suppliers keyed by phone for clarity
        $oilSupplier      = Supplier::where('phone', '01100000001')->first(); // oils
        $oudSupplier      = Supplier::where('phone', '01100000002')->first(); // oud & incense
        $bottleSupplier   = Supplier::where('phone', '01100000003')->first(); // bottles
        $baseSupplier     = Supplier::where('phone', '01100000004')->first(); // carriers
        $readySupplier    = Supplier::where('phone', '01100000005')->first(); // ready-made

        // Products keyed by SKU for easy lookup
        $products = Product::pluck('id', 'sku');

        // ══════════════════════════════════════════════════════════════════════
        // Supply 1 → الفرع الرئيسي: Fragrance oils + carrier bases
        // ══════════════════════════════════════════════════════════════════════
        $supply1 = Supply::create([
            'supplier_id'    => $oilSupplier->id,
            'date'           => now()->subDays(14)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply1, $shop1->id, [
            // Raw fragrance oil concentrates — qty in ml, price per ml
            ['sku' => 'OIL-001', 'qty' => 500,  'price' => 25.00],  // عود هندي — 25 ج/مل
            ['sku' => 'OIL-002', 'qty' => 300,  'price' => 35.00],  // عود كمبودي — 35 ج/مل
            ['sku' => 'OIL-003', 'qty' => 400,  'price' => 18.00],  // ورد الطائف — 18 ج/مل
            ['sku' => 'OIL-004', 'qty' => 600,  'price' => 10.00],  // مسك أبيض — 10 ج/مل
            ['sku' => 'OIL-005', 'qty' => 300,  'price' => 12.00],  // ياسمين — 12 ج/مل
            ['sku' => 'OIL-006', 'qty' => 500,  'price' => 6.00],   // فانيليا — 6 ج/مل
            ['sku' => 'OIL-007', 'qty' => 400,  'price' => 14.00],  // صندل — 14 ج/مل
            ['sku' => 'OIL-008', 'qty' => 500,  'price' => 7.00],   // برغموت — 7 ج/مل
            ['sku' => 'OIL-009', 'qty' => 600,  'price' => 5.00],   // أرز — 5 ج/مل
            ['sku' => 'OIL-010', 'qty' => 500,  'price' => 4.50],   // لافندر — 4.5 ج/مل
            ['sku' => 'OIL-011', 'qty' => 400,  'price' => 8.00],   // باتشولي — 8 ج/مل
            ['sku' => 'OIL-012', 'qty' => 300,  'price' => 20.00],  // عنبر أسود — 20 ج/مل
        ], $products);

        // ══════════════════════════════════════════════════════════════════════
        // Supply 2 → الفرع الرئيسي: Oud & incense + bases + bottles + packaging
        // ══════════════════════════════════════════════════════════════════════
        $supply2a = Supply::create([
            'supplier_id'    => $oudSupplier->id,
            'date'           => now()->subDays(12)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply2a, $shop1->id, [
            // Oud & incense — qty in grams, price per gram
            ['sku' => 'OUD-001', 'qty' => 200,   'price' => 80.00],  // عود هندي خام — 80 ج/ج
            ['sku' => 'OUD-002', 'qty' => 150,   'price' => 120.00], // عود كمبودي — 120 ج/ج
            ['sku' => 'OUD-003', 'qty' => 100,   'price' => 150.00], // عود بروني — 150 ج/ج
            ['sku' => 'OUD-004', 'qty' => 500,   'price' => 5.00],   // بخور دلال — 5 ج/ج
            ['sku' => 'OUD-005', 'qty' => 500,   'price' => 4.00],   // بخور الرياض — 4 ج/ج
            ['sku' => 'OUD-006', 'qty' => 50,    'price' => 200.00], // عنبر خام — 200 ج/ج
            ['sku' => 'OUD-007', 'qty' => 30,    'price' => 250.00], // مسك غزال — 250 ج/ج
        ], $products);

        $supply2b = Supply::create([
            'supplier_id'    => $baseSupplier->id,
            'date'           => now()->subDays(12)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply2b, $shop1->id, [
            // Carrier bases — qty in ml, price per ml (cheap bulk)
            ['sku' => 'BAS-001', 'qty' => 10000, 'price' => 0.30],  // كحول 96%
            ['sku' => 'BAS-002', 'qty' => 8000,  'price' => 0.40],  // DPG
            ['sku' => 'BAS-003', 'qty' => 5000,  'price' => 0.80],  // جوجوبا
            ['sku' => 'BAS-004', 'qty' => 4000,  'price' => 0.60],  // IPM
            ['sku' => 'BAS-005', 'qty' => 6000,  'price' => 0.50],  // قاعدة محايدة
        ], $products);

        $supply2c = Supply::create([
            'supplier_id'    => $bottleSupplier->id,
            'date'           => now()->subDays(10)->toDateString(),
            'payment_method' => 'debt',
        ]);

        $this->addItems($supply2c, $shop1->id, [
            // Bottles & tools — qty in pcs, price per piece
            ['sku' => 'BTL-001', 'qty' => 100,  'price' => 8.00],   // زجاجة رش 50مل
            ['sku' => 'BTL-002', 'qty' => 100,  'price' => 12.00],  // زجاجة رش 100مل
            ['sku' => 'BTL-003', 'qty' => 200,  'price' => 4.00],   // رولون 10مل
            ['sku' => 'BTL-004', 'qty' => 200,  'price' => 3.00],   // رولون 6مل
            ['sku' => 'BTL-005', 'qty' => 150,  'price' => 7.00],   // أتومايزر
            ['sku' => 'BTL-006', 'qty' => 80,   'price' => 6.00],   // قارورة 30مل
            ['sku' => 'BTL-007', 'qty' => 30,   'price' => 15.00],  // قمع ستانلس
            ['sku' => 'BTL-008', 'qty' => 50,   'price' => 3.00],   // ماصة قياس
            // Packaging
            ['sku' => 'PKG-001', 'qty' => 100,  'price' => 12.00],  // علبة صغيرة
            ['sku' => 'PKG-002', 'qty' => 60,   'price' => 20.00],  // علبة كبيرة
            ['sku' => 'PKG-003', 'qty' => 200,  'price' => 5.00],   // كيس هدية
            ['sku' => 'PKG-004', 'qty' => 80,   'price' => 8.00],   // ليبل لاصق
            ['sku' => 'PKG-005', 'qty' => 50,   'price' => 3.00],   // شريط لاصق
        ], $products);

        // ══════════════════════════════════════════════════════════════════════
        // Supply 3 → الفرع الرئيسي: Ready-made perfumes (from importer)
        // ══════════════════════════════════════════════════════════════════════
        $supply3 = Supply::create([
            'supplier_id'    => $readySupplier->id,
            'date'           => now()->subDays(7)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply3, $shop1->id, [
            // Ready-made bottled perfumes — qty in pcs, price = wholesale cost per bottle
            ['sku' => 'PRF-001', 'qty' => 30, 'price' => 120.00],  // عود الكبير 100مل
            ['sku' => 'PRF-002', 'qty' => 40, 'price' => 80.00],   // مسك الأبيض 50مل
            ['sku' => 'PRF-003', 'qty' => 35, 'price' => 130.00],  // ورد الطائف 100مل
            ['sku' => 'PRF-004', 'qty' => 25, 'price' => 110.00],  // البخور الفاخر 75مل
            ['sku' => 'PRF-005', 'qty' => 30, 'price' => 140.00],  // الأمير الأسود 100مل
            ['sku' => 'PRF-006', 'qty' => 40, 'price' => 75.00],   // الفردوس 50مل
            ['sku' => 'PRF-007', 'qty' => 30, 'price' => 120.00],  // نسمة الصحراء 100مل
            ['sku' => 'PRF-008', 'qty' => 25, 'price' => 100.00],  // اللؤلؤ الذهبي 75مل
            ['sku' => 'PRF-009', 'qty' => 20, 'price' => 130.00],  // ليلة العرب 100مل
            ['sku' => 'PRF-010', 'qty' => 35, 'price' => 70.00],   // مسك الجنة 50مل
        ], $products);

        // ══════════════════════════════════════════════════════════════════════
        // Supply 4 → فرع الشمال: Full stock (smaller quantities)
        // ══════════════════════════════════════════════════════════════════════
        $supply4a = Supply::create([
            'supplier_id'    => $oilSupplier->id,
            'date'           => now()->subDays(5)->toDateString(),
            'payment_method' => 'debt',
        ]);

        $this->addItems($supply4a, $shop2->id, [
            // Oils — ml
            ['sku' => 'OIL-001', 'qty' => 250,  'price' => 25.00],
            ['sku' => 'OIL-002', 'qty' => 150,  'price' => 35.00],
            ['sku' => 'OIL-003', 'qty' => 200,  'price' => 18.00],
            ['sku' => 'OIL-004', 'qty' => 300,  'price' => 10.00],
            ['sku' => 'OIL-005', 'qty' => 150,  'price' => 12.00],
            ['sku' => 'OIL-006', 'qty' => 250,  'price' => 6.00],
            ['sku' => 'OIL-007', 'qty' => 200,  'price' => 14.00],
            ['sku' => 'OIL-008', 'qty' => 250,  'price' => 7.00],
            ['sku' => 'OIL-010', 'qty' => 300,  'price' => 4.50],
            ['sku' => 'OIL-012', 'qty' => 150,  'price' => 20.00],
        ], $products);

        $supply4b = Supply::create([
            'supplier_id'    => $oudSupplier->id,
            'date'           => now()->subDays(5)->toDateString(),
            'payment_method' => 'debt',
        ]);

        $this->addItems($supply4b, $shop2->id, [
            // Oud & incense — grams
            ['sku' => 'OUD-001', 'qty' => 100,  'price' => 80.00],
            ['sku' => 'OUD-002', 'qty' => 80,   'price' => 120.00],
            ['sku' => 'OUD-004', 'qty' => 300,  'price' => 5.00],
            ['sku' => 'OUD-005', 'qty' => 300,  'price' => 4.00],
            ['sku' => 'OUD-006', 'qty' => 25,   'price' => 200.00],
        ], $products);

        $supply4c = Supply::create([
            'supplier_id'    => $baseSupplier->id,
            'date'           => now()->subDays(4)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply4c, $shop2->id, [
            // Bases — ml
            ['sku' => 'BAS-001', 'qty' => 5000, 'price' => 0.30],
            ['sku' => 'BAS-002', 'qty' => 4000, 'price' => 0.40],
            ['sku' => 'BAS-003', 'qty' => 2500, 'price' => 0.80],
            ['sku' => 'BAS-005', 'qty' => 3000, 'price' => 0.50],
        ], $products);

        $supply4d = Supply::create([
            'supplier_id'    => $bottleSupplier->id,
            'date'           => now()->subDays(3)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply4d, $shop2->id, [
            // Bottles — pcs
            ['sku' => 'BTL-001', 'qty' => 60,  'price' => 8.00],
            ['sku' => 'BTL-002', 'qty' => 60,  'price' => 12.00],
            ['sku' => 'BTL-003', 'qty' => 100, 'price' => 4.00],
            ['sku' => 'BTL-004', 'qty' => 100, 'price' => 3.00],
            ['sku' => 'BTL-005', 'qty' => 80,  'price' => 7.00],
            // Packaging
            ['sku' => 'PKG-001', 'qty' => 60,  'price' => 12.00],
            ['sku' => 'PKG-002', 'qty' => 40,  'price' => 20.00],
            ['sku' => 'PKG-003', 'qty' => 100, 'price' => 5.00],
        ], $products);

        $supply4e = Supply::create([
            'supplier_id'    => $readySupplier->id,
            'date'           => now()->subDays(2)->toDateString(),
            'payment_method' => 'immediate',
        ]);

        $this->addItems($supply4e, $shop2->id, [
            // Ready-made — pcs
            ['sku' => 'PRF-001', 'qty' => 15, 'price' => 120.00],
            ['sku' => 'PRF-002', 'qty' => 20, 'price' => 80.00],
            ['sku' => 'PRF-003', 'qty' => 15, 'price' => 130.00],
            ['sku' => 'PRF-005', 'qty' => 15, 'price' => 140.00],
            ['sku' => 'PRF-006', 'qty' => 20, 'price' => 75.00],
            ['sku' => 'PRF-008', 'qty' => 12, 'price' => 100.00],
            ['sku' => 'PRF-010', 'qty' => 18, 'price' => 70.00],
        ], $products);
    }

    /**
     * Creates SupplyItems for a supply and a corresponding Goods row
     * (current_quantity = purchased quantity) in the given shop.
     *
     * @param  \App\Models\Supply              $supply
     * @param  int                             $shopId
     * @param  array<array{sku,qty,price}>     $items
     * @param  \Illuminate\Support\Collection  $products  sku → id
     */
    private function addItems(Supply $supply, int $shopId, array $items, $products): void
    {
        foreach ($items as $item) {
            $productId = $products[$item['sku']] ?? null;

            if (! $productId) {
                $this->command->warn("Product SKU {$item['sku']} not found — skipping.");
                continue;
            }

            $supplyItem = SupplyItem::create([
                'supply_id'  => $supply->id,
                'product_id' => $productId,
                'quantity'   => $item['qty'],
                'unit_price' => $item['price'],
            ]);

            Goods::create([
                'supply_item_id'   => $supplyItem->id,
                'shop_id'          => $shopId,
                'current_quantity' => $item['qty'],
                'date'             => $supply->date,
            ]);
        }
    }
}
