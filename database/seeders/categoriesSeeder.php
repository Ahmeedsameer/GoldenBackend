<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class categoriesSeeder extends Seeder
{
    /**
     * Categories for a perfume company that:
     *   - Sells ready-made bottled perfumes (by piece)
     *   - Sells raw fragrance oils for custom blending (by ml)
     *   - Sells oud and incense by weight (by g)
     *   - Sells carrier/base materials for mixing (by ml)
     *   - Sells bottles and accessories at fixed retail prices (by piece)
     *   - Sells packaging at fixed prices (by piece)
     *
     * Pricing logic:
     *   is_fixed = true  → item always sells at exactly minimum_sell_price per unit
     *   is_fixed = false → unit price is computed from the invoice global-total pool;
     *                      value_percentage is the weight used in the pool split —
     *                      higher % = this category absorbs more of the invoice total
     */
    public function run(): void
    {
        $categories = [
            // ── Fixed-price categories ────────────────────────────────────────
            // Bottles/tools have a clear, standard retail price per piece
            [
                'name'               => 'عبوات وأدوات',
                'description'        => 'زجاجات عطور، رولونات، أتومايزرات، وأدوات التعبئة',
                'minimum_sell_price' => 15.00,
                'is_fixed'           => true,
                'value_percentage'   => null,
            ],
            // Packaging items (boxes, bags, labels) also fixed
            [
                'name'               => 'مستلزمات التغليف',
                'description'        => 'علب هدايا، أكياس، ليبلات — تباع بسعر ثابت للقطعة',
                'minimum_sell_price' => 5.00,
                'is_fixed'           => true,
                'value_percentage'   => null,
            ],

            // ── Weighted (non-fixed) categories ──────────────────────────────
            // Ready-made perfumes: highest value in most invoices
            [
                'name'               => 'عطور جاهزة',
                'description'        => 'عطور معبأة جاهزة للبيع — رجالي ونسائي وعربي وغربي',
                'minimum_sell_price' => 150.00,
                'is_fixed'           => false,
                'value_percentage'   => 55.00,
            ],
            // Raw fragrance oil concentrates: very high value per ml
            [
                'name'               => 'زيوت عطرية',
                'description'        => 'زيوت ومركزات عطرية خام تُباع بالمليليتر للتركيب المخصص',
                'minimum_sell_price' => 8.00,
                'is_fixed'           => false,
                'value_percentage'   => 40.00,
            ],
            // Oud and incense: sold by gram, high value
            [
                'name'               => 'بخور وعود',
                'description'        => 'عود خام، بخور، عنبر، ومسك — تُباع بالجرام',
                'minimum_sell_price' => 15.00,
                'is_fixed'           => false,
                'value_percentage'   => 50.00,
            ],
            // Carrier bases and solvents: low cost per ml, bulk quantities
            [
                'name'               => 'قواعد وحوامل',
                'description'        => 'كحول عطري، DPG، جوجوبا، وقواعد التخفيف — بالمليليتر',
                'minimum_sell_price' => 0.50,
                'is_fixed'           => false,
                'value_percentage'   => 5.00,
            ],
        ];

        foreach ($categories as $cat) {
            Category::create($cat);
        }
    }
}
