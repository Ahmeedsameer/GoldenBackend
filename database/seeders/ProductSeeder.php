<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Products for a perfume company.
     *
     * scalar values:
     *   pcs → sold by piece (ready-made perfumes, bottles, packaging)
     *   ml  → sold by millilitre (fragrance oils, carrier bases)
     *   g   → sold by gram (oud, incense, raw resins)
     */
    public function run(): void
    {
        $cat = Category::pluck('id', 'name');

        $products = [
            // ── عطور جاهزة — Ready-made perfumes (pcs) ───────────────────────
            // Pre-bottled, labelled, ready for retail sale
            ['name' => 'عود الكبير 100مل',          'sku' => 'PRF-001', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'مسك الأبيض 50مل',           'sku' => 'PRF-002', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'ورد الطائف 100مل',           'sku' => 'PRF-003', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'البخور الفاخر 75مل',         'sku' => 'PRF-004', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'الأمير الأسود 100مل',        'sku' => 'PRF-005', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'الفردوس 50مل',               'sku' => 'PRF-006', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'نسمة الصحراء 100مل',         'sku' => 'PRF-007', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'اللؤلؤ الذهبي 75مل',         'sku' => 'PRF-008', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'ليلة العرب 100مل',           'sku' => 'PRF-009', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],
            ['name' => 'مسك الجنة 50مل',             'sku' => 'PRF-010', 'scalar' => 'pcs', 'category' => 'عطور جاهزة'],

            // ── زيوت عطرية — Raw fragrance oil concentrates (ml) ─────────────
            // Sold by the ml; used in custom blending or decanted into client bottles
            ['name' => 'زيت عود هندي خام',           'sku' => 'OIL-001', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت عود كمبودي خام',          'sku' => 'OIL-002', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت ورد الطائف المركز',       'sku' => 'OIL-003', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت مسك أبيض',               'sku' => 'OIL-004', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت ياسمين مركز',             'sku' => 'OIL-005', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت فانيليا',                 'sku' => 'OIL-006', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت الصندل',                  'sku' => 'OIL-007', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت برغموت',                  'sku' => 'OIL-008', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت الأرز (سيدار وود)',        'sku' => 'OIL-009', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت اللافندر',                'sku' => 'OIL-010', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت الباتشولي',               'sku' => 'OIL-011', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],
            ['name' => 'زيت العنبر الأسود',           'sku' => 'OIL-012', 'scalar' => 'ml',  'category' => 'زيوت عطرية'],

            // ── بخور وعود — Oud & Incense (g) ────────────────────────────────
            // Sold by gram on a precision scale
            ['name' => 'عود هندي خام',               'sku' => 'OUD-001', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'عود كمبودي خام',              'sku' => 'OUD-002', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'عود بروني درجة أولى',         'sku' => 'OUD-003', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'بخور دلال مميز',              'sku' => 'OUD-004', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'بخور الرياض',                 'sku' => 'OUD-005', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'عنبر خام (أمبرجريس)',         'sku' => 'OUD-006', 'scalar' => 'g',   'category' => 'بخور وعود'],
            ['name' => 'مسك غزال خام',               'sku' => 'OUD-007', 'scalar' => 'g',   'category' => 'بخور وعود'],

            // ── قواعد وحوامل — Carrier bases (ml) ───────────────────────────
            // Diluents and carriers for custom blending; large volumes, low cost per ml
            ['name' => 'كحول عطري 96%',              'sku' => 'BAS-001', 'scalar' => 'ml',  'category' => 'قواعد وحوامل'],
            ['name' => 'DPG ثنائي بروبيلين جليكول',   'sku' => 'BAS-002', 'scalar' => 'ml',  'category' => 'قواعد وحوامل'],
            ['name' => 'زيت الجوجوبا',                'sku' => 'BAS-003', 'scalar' => 'ml',  'category' => 'قواعد وحوامل'],
            ['name' => 'IPM (إيزوبروبيل ميريستيت)',   'sku' => 'BAS-004', 'scalar' => 'ml',  'category' => 'قواعد وحوامل'],
            ['name' => 'قاعدة عطور محايدة',           'sku' => 'BAS-005', 'scalar' => 'ml',  'category' => 'قواعد وحوامل'],

            // ── عبوات وأدوات — Bottles & tools (pcs, fixed price) ────────────
            ['name' => 'زجاجة رش 50مل فاخرة',        'sku' => 'BTL-001', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'زجاجة رش 100مل فاخرة',       'sku' => 'BTL-002', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'زجاجة رولون 10مل',            'sku' => 'BTL-003', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'زجاجة رولون 6مل',             'sku' => 'BTL-004', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'أتومايزر محمول 10مل',         'sku' => 'BTL-005', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'قارورة عطر زجاج شفاف 30مل',  'sku' => 'BTL-006', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'قمع تعبئة ستانلس ستيل',       'sku' => 'BTL-007', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],
            ['name' => 'ماصة قياس 5مل',               'sku' => 'BTL-008', 'scalar' => 'pcs', 'category' => 'عبوات وأدوات'],

            // ── مستلزمات التغليف — Packaging (pcs, fixed price) ──────────────
            ['name' => 'علبة هدايا صغيرة مخملية',    'sku' => 'PKG-001', 'scalar' => 'pcs', 'category' => 'مستلزمات التغليف'],
            ['name' => 'علبة هدايا كبيرة مخملية',    'sku' => 'PKG-002', 'scalar' => 'pcs', 'category' => 'مستلزمات التغليف'],
            ['name' => 'كيس هدية فاخر',              'sku' => 'PKG-003', 'scalar' => 'pcs', 'category' => 'مستلزمات التغليف'],
            ['name' => 'ليبل لاصق للعبوات (20 قطعة)', 'sku' => 'PKG-004', 'scalar' => 'pcs', 'category' => 'مستلزمات التغليف'],
            ['name' => 'شريط لاصق شفاف للتغليف',     'sku' => 'PKG-005', 'scalar' => 'pcs', 'category' => 'مستلزمات التغليف'],
        ];

        foreach ($products as $p) {
            Product::create([
                'name'        => $p['name'],
                'sku'         => $p['sku'],
                'scalar'      => $p['scalar'],
                'is_active'   => true,
                'category_id' => $cat[$p['category']] ?? null,
            ]);
        }
    }
}
