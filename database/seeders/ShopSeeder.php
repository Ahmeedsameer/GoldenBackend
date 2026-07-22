<?php

namespace Database\Seeders;

use App\Models\Safe;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopSeeder extends Seeder
{
    /**
     * Creates shops, links managers, assigns sellers, and creates a physical safe
     * for each shop. Runs AFTER UserSeeder.
     */
    public function run(): void
    {
        $physicalTypeId = DB::table('safe_types')->where('kind', 'physical')->value('id');

        $manager1 = User::where('email', 'manager1@alpha.com')->first();
        $manager2 = User::where('email', 'manager2@alpha.com')->first();
        $admin = User::where('role', 'admin')->first();

        // ── Main Warehouse — a real Shop row (Phase 5.1/5.2) so Transfers,
        // Reports, Dashboards and Invoices need zero special-casing for it.
        // Goods.shop_id = NULL still means "physically in the warehouse" —
        // WarehouseResolver bridges this Shop's id to that NULL convention
        // inside the inventory layer only. Admin acts as its manager.
        Shop::create([
            'name'         => 'المستودع الرئيسي',
            'address'      => 'المستودع المركزي',
            'status'       => 'active',
            'manager_id'   => $admin?->id,
            'is_warehouse' => true,
        ]);

        // ── Shop 1: Main Branch ───────────────────────────────────────────────
        $shop1 = Shop::create([
            'name'       => 'الفرع الرئيسي',
            'address'    => 'شارع التحرير، وسط البلد، القاهرة',
            'status'     => 'active',
            'manager_id' => $manager1?->id,
        ]);

        // ── Shop 2: North Branch ──────────────────────────────────────────────
        $shop2 = Shop::create([
            'name'       => 'فرع الشمال',
            'address'    => 'شارع كورنيش الإسكندرية، سيدي جابر، الإسكندرية',
            'status'     => 'active',
            'manager_id' => $manager2?->id,
        ]);

        // ── Assign shop_id to managers and sellers ─────────────────────────────
        $manager1?->update(['shop_id' => $shop1->id]);
        $manager2?->update(['shop_id' => $shop2->id]);

        User::where('email', 'seller1@alpha.com')->update(['shop_id' => $shop1->id]);
        User::where('email', 'seller2@alpha.com')->update(['shop_id' => $shop1->id]);
        User::where('email', 'seller3@alpha.com')->update(['shop_id' => $shop2->id]);
        User::where('email', 'seller4@alpha.com')->update(['shop_id' => $shop2->id]);

        // ── Create a physical safe for each shop ──────────────────────────────
        if ($physicalTypeId) {
            Safe::create([
                'shop_id'      => $shop1->id,
                'safe_type_id' => $physicalTypeId,
                'is_active'    => true,
            ]);

            Safe::create([
                'shop_id'      => $shop2->id,
                'safe_type_id' => $physicalTypeId,
                'is_active'    => true,
            ]);
        }
    }
}
