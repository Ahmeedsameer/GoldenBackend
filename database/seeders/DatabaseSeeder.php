<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Run order respects FK dependencies:
     *
     *  1. TransactionReasonSeeder  — lookup table, no FK deps
     *  2. UserSeeder               — creates users (no shop_id yet)
     *  3. ShopSeeder               — creates shops, links managers + sellers,
     *                                creates one physical safe per shop
     *  4. categoriesSeeder         — categories with is_fixed / value_percentage
     *  5. ProductSeeder            — products linked to categories
     *  6. SupplierSeeder           — suppliers
     *  7. SupplySeeder             — supplies → supply_items → goods (inventory)
     *
     * Note: currencies and safe_types are already seeded inside their
     *       respective migration files and do NOT need a seeder here.
     */
    public function run(): void
    {
        $this->call([
            TransactionReasonSeeder::class,
            UserSeeder::class,
            ShopSeeder::class,
            categoriesSeeder::class,
            ProductSeeder::class,
            SupplierSeeder::class,
            SupplySeeder::class,
            HrDeductionSettingSeeder::class,   // HR: default (configurable) deduction rules
        ]);
    }
}
