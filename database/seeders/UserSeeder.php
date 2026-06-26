<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates a predictable set of users with known credentials:
     *
     *  Role     | Email                  | Password
     * ----------|------------------------|----------
     *  admin    | admin@alpha.com        | admin123
     *  manager  | manager1@alpha.com     | manager123
     *  manager  | manager2@alpha.com     | manager123
     *  sales    | seller1@alpha.com      | seller123   (shop 1)
     *  sales    | seller2@alpha.com      | seller123   (shop 1)
     *  sales    | seller3@alpha.com      | seller123   (shop 2)
     *  sales    | seller4@alpha.com      | seller123   (shop 2)
     *
     * shop_id is NOT assigned here — ShopSeeder does that after shops exist.
     */
    public function run(): void
    {
        // ── Admin ──────────────────────────────────────────────────────────────
        User::create([
            'name'        => 'المدير العام',
            'email'       => 'admin@alpha.com',
            'phone'       => '01000000001',
            'password'    => Hash::make('admin123'),
            'role'        => 'admin',
            'shift_start' => '08:00:00',
            'shift_end'   => '17:00:00',
        ]);

        // ── Managers (shop assigned in ShopSeeder) ─────────────────────────────
        User::create([
            'name'        => 'مدير الفرع الرئيسي',
            'email'       => 'manager1@alpha.com',
            'phone'       => '01000000002',
            'password'    => Hash::make('manager123'),
            'role'        => 'manager',
            'shift_start' => '08:00:00',
            'shift_end'   => '17:00:00',
        ]);

        User::create([
            'name'        => 'مدير فرع الشمال',
            'email'       => 'manager2@alpha.com',
            'phone'       => '01000000003',
            'password'    => Hash::make('manager123'),
            'role'        => 'manager',
            'shift_start' => '09:00:00',
            'shift_end'   => '18:00:00',
        ]);

        // ── Sellers – shop_id set in ShopSeeder ────────────────────────────────
        User::create([
            'name'        => 'بائع 1 - الفرع الرئيسي',
            'email'       => 'seller1@alpha.com',
            'phone'       => '01000000004',
            'password'    => Hash::make('seller123'),
            'role'        => 'sales',
            'shift_start' => '08:00:00',
            'shift_end'   => '14:00:00',
        ]);

        User::create([
            'name'        => 'بائع 2 - الفرع الرئيسي',
            'email'       => 'seller2@alpha.com',
            'phone'       => '01000000005',
            'password'    => Hash::make('seller123'),
            'role'        => 'sales',
            'shift_start' => '14:00:00',
            'shift_end'   => '22:00:00',
        ]);

        User::create([
            'name'        => 'بائع 1 - فرع الشمال',
            'email'       => 'seller3@alpha.com',
            'phone'       => '01000000006',
            'password'    => Hash::make('seller123'),
            'role'        => 'sales',
            'shift_start' => '08:00:00',
            'shift_end'   => '14:00:00',
        ]);

        User::create([
            'name'        => 'بائع 2 - فرع الشمال',
            'email'       => 'seller4@alpha.com',
            'phone'       => '01000000007',
            'password'    => Hash::make('seller123'),
            'role'        => 'sales',
            'shift_start' => '14:00:00',
            'shift_end'   => '22:00:00',
        ]);
    }
}
