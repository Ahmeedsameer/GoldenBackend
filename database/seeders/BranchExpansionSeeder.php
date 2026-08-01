<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Safe;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Reference-data expansion for Phase 2: new branches (with manager + sellers
 * + safe), a wider product catalog, more suppliers, and a much larger
 * customer pool. Pure reference/setup data — the actual transactional
 * history for these branches is generated separately by BranchActivitySeeder
 * (which needs this data to already exist).
 */
class BranchExpansionSeeder extends Seeder
{
    /** name => [address, opened_on, tier] — tier drives performance in BranchActivitySeeder. */
    public const NEW_BRANCHES = [
        'فرع مدينة نصر'   => ['شارع عباس العقاد، مدينة نصر، القاهرة',        '2026-05-01', 'strong'],
        'فرع المعادي'      => ['شارع 9، المعادي، القاهرة',                    '2026-05-01', 'strong'],
        'فرع مصر الجديدة'  => ['شارع الميرغني، مصر الجديدة، القاهرة',        '2026-05-15', 'medium'],
        'فرع الإسكندرية'   => ['طريق الحرية، سموحة، الإسكندرية',              '2026-06-01', 'medium'],
        'فرع المنصورة'     => ['شارع الجمهورية، المنصورة، الدقهلية',          '2026-06-01', 'medium'],
        'فرع طنطا'        => ['شارع سعيد، طنطا، الغربية',                    '2026-07-01', 'weak'],
        'فرع الغردقة'      => ['شارع الشيراتون، الغردقة، البحر الأحمر',       '2026-07-01', 'weak'],
        'فرع أسيوط'       => ['شارع الثورة، أسيوط',                          '2026-07-10', 'new'],
    ];

    public function run(): void
    {
        $this->createBranches();
        $this->expandProductCatalog();
        $this->expandSuppliers();
        $this->expandCustomers();
    }

    private function createBranches(): void
    {
        $physicalTypeId = DB::table('safe_types')->where('kind', 'physical')->value('id');
        $existingEmailBase = 'branch' . (User::count());

        foreach (self::NEW_BRANCHES as $name => [$address, $openedOn, $tier]) {
            if (Shop::where('name', $name)->exists()) {
                continue; // already seeded on a previous run
            }

            $shop = Shop::create([
                'name'    => $name,
                'address' => $address,
                'status'  => 'active',
            ]);

            $slug = $this->transliterate($name);

            $manager = User::create([
                'name'                        => 'مدير ' . $name,
                'email'                       => "manager.{$slug}@alpha.com",
                'password'                    => Hash::make('manager123'),
                'phone'                       => $this->uniquePhone(),
                'role'                        => 'manager',
                'shop_id'                     => $shop->id,
                'status'                      => 'active',
                'base_salary'                 => match ($tier) {
                    'strong' => 7000, 'medium' => 6000, 'weak' => 5000, default => 5500,
                },
                'personal_commission_percent' => 1.0,
                'hire_date'                   => $openedOn,
                'monthly_leave_allowance'     => 2,
            ]);
            $shop->update(['manager_id' => $manager->id]);

            $sellerCount = match ($tier) {
                'strong' => 3, 'medium' => 2, default => 1,
            };
            for ($i = 1; $i <= $sellerCount; $i++) {
                User::create([
                    'name'                        => "بائع {$i} - {$name}",
                    'email'                       => "seller{$i}.{$slug}@alpha.com",
                    'password'                    => Hash::make('seller123'),
                    'phone'                       => $this->uniquePhone(),
                    'role'                        => 'sales',
                    'shop_id'                     => $shop->id,
                    'status'                      => 'active',
                    'base_salary'                 => 2200,
                    'personal_commission_percent' => 3.0,
                    'hire_date'                   => $openedOn,
                    'monthly_leave_allowance'     => 2,
                ]);
            }

            if ($physicalTypeId) {
                Safe::create([
                    'shop_id'      => $shop->id,
                    'safe_type_id' => $physicalTypeId,
                    'is_active'    => true,
                ]);
            }
        }

        // ── One resigned employee (churn realism) at an established branch ──
        $mainBranch = Shop::where('name', 'الفرع الرئيسي')->first();
        if ($mainBranch && ! User::where('email', 'resigned.seller@alpha.com')->exists()) {
            User::create([
                'name'                        => 'كريم صلاح',
                'email'                       => 'resigned.seller@alpha.com',
                'password'                    => Hash::make('seller123'),
                'phone'                       => $this->uniquePhone(),
                'role'                        => 'sales',
                'shop_id'                     => $mainBranch->id,
                'status'                      => 'inactive',
                'base_salary'                 => 2200,
                'personal_commission_percent' => 3.0,
                'hire_date'                   => '2026-05-10',
                'monthly_leave_allowance'     => 2,
                'hr_notes'                    => 'استقال بتاريخ 2026-06-30 — انتقل لمدينة أخرى',
            ]);
        }
    }

    private function expandProductCatalog(): void
    {
        $oilsCat = Category::where('name', 'زيوت عطرية')->value('id');
        $readyCat = Category::where('name', 'عطور جاهزة')->value('id');
        $bottleCat = Category::where('name', 'عبوات وأدوات')->value('id');
        $packagingCat = Category::where('name', 'مستلزمات التغليف')->value('id');
        $incenseCat = Category::where('name', 'بخور وعود')->value('id');

        $newOils = [
            ['زيت المسك الأسود', 24], ['زيت العنبر الملكي', 26], ['زيت خشب الورد', 13],
            ['زيت الزعفران', 60], ['زيت النارجيلة', 9],
        ];
        foreach ($newOils as [$name, $pricePerGram]) {
            $this->firstOrCreateProduct($name, [
                'product_type' => 'raw_material', 'scalar' => 'ml', 'category_id' => $oilsCat,
                'price_per_gram' => $pricePerGram, 'is_active' => true, 'show_in_catalog' => false,
                'warning_quantity' => 200, 'critical_quantity' => 50,
            ]);
        }

        $newBottles = [
            ['زجاجة رول أون 15مل', 15, 4.5], ['زجاجة سبراي فاخرة 75مل', 75, 11],
            ['قارورة كريستال 100مل', 100, 22],
        ];
        foreach ($newBottles as [$name, $cap, $price]) {
            $this->firstOrCreateProduct($name, [
                'product_type' => 'packaging', 'scalar' => 'pcs', 'category_id' => $bottleCat,
                'selling_price' => $price, 'capacity_ml' => $cap, 'is_active' => true, 'show_in_catalog' => false,
                'warning_quantity' => 100, 'critical_quantity' => 20,
            ]);
        }

        $newPackaging = [
            ['علبة تغليف فاخرة ذهبية', 25], ['ريبون ساتان للتغليف', 6],
        ];
        foreach ($newPackaging as [$name, $price]) {
            $this->firstOrCreateProduct($name, [
                'product_type' => 'packaging', 'scalar' => 'pcs', 'category_id' => $packagingCat,
                'selling_price' => $price, 'is_active' => true, 'show_in_catalog' => false,
                'warning_quantity' => 100, 'critical_quantity' => 20,
            ]);
        }

        // Ready products — deliberately varied stock personalities (set up properly in BranchActivitySeeder/EdgeCaseSeeder):
        $newReady = [
            ['عنبر الملوك 100مل', $readyCat, 520],
            ['ياسمين الشام 75مل', $readyCat, 340],
            ['مسك الحرمين 50مل', $readyCat, 300],
            ['واحة الصحراء 100مل', $readyCat, 410],
            ['ندى الورد 50مل', $readyCat, 250],
            ['عود ملكي فاخر 30مل', $incenseCat, 600], // slow-moving luxury item — expensive, low volume
        ];
        foreach ($newReady as [$name, $cat, $price]) {
            $this->firstOrCreateProduct($name, [
                'product_type' => 'ready_product', 'scalar' => 'pcs', 'category_id' => $cat,
                'selling_price' => $price, 'is_active' => true, 'show_in_catalog' => true,
                'warning_quantity' => 20, 'critical_quantity' => 5,
            ]);
        }

        // Compounds — more Product Builder catalog variety
        $newCompounds = [
            ['ليالي دبي'], ['سلطان العود'], ['زهرة الصحراء'],
        ];
        foreach ($newCompounds as [$name]) {
            $this->firstOrCreateProduct($name, [
                'product_type' => 'compound', 'scalar' => 'pcs', 'category_id' => Category::where('name', 'عطور مركبة')->value('id'),
                'is_active' => true, 'show_in_catalog' => true,
            ]);
        }
    }

    private function firstOrCreateProduct(string $name, array $attrs): Product
    {
        return Product::firstOrCreate(['name' => $name], $attrs);
    }

    private function expandSuppliers(): void
    {
        $suppliers = [
            ['اتحاد موردي العطور بالجملة', '01500000010', 'شارع الموسكي، القاهرة'],
            ['شركة الدلتا للزجاجات والعبوات', '01500000011', 'المنطقة الصناعية، العاشر من رمضان'],
            ['مؤسسة الخليج للمواد الخام', '01500000012', 'ميناء جدة الإسلامي (استيراد)'],
            ['مصنع النيل للتغليف الفاخر', '01500000013', 'المنطقة الصناعية الثالثة، برج العرب'],
        ];

        foreach ($suppliers as [$name, $phone, $address]) {
            $supplier = Supplier::firstOrCreate(['name' => $name], [
                'phone' => $phone, 'address' => $address,
            ]);
            if (! $supplier->wasRecentlyCreated) {
                continue;
            }
            SupplierContact::create([
                'supplier_id' => $supplier->id,
                'name'        => 'مسؤول المبيعات',
                'position'    => 'مندوب مبيعات',
                'phone'       => $phone,
                'address'     => $address,
            ]);
        }
    }

    private function expandCustomers(): void
    {
        $names = [
            'عمر خالد', 'ليلى حسن', 'فاطمة الزهراء', 'إسلام عبد الله', 'رنا مجدي',
            'طارق سعيد', 'دينا فؤاد', 'حسام الدين', 'ياسمين علي', 'وليد نبيل',
            'سلمى إبراهيم', 'كريم عادل', 'منى شريف', 'أحمد رفعت', 'إيمان صابر',
            'محمود عزت', 'نهى كامل', 'شريف حمدي', 'أميرة توفيق', 'باسل مراد',
            'رشا جمال', 'عادل فتحي', 'هالة نصر', 'مصطفى راضي', 'جيهان سامي',
            'عبد الرحمن ياسر', 'نادية حلمي', 'أيمن لطفي', 'سحر عاطف', 'زياد منير',
        ];

        foreach ($names as $i => $name) {
            $phone = '015' . str_pad((string) (50000 + $i), 8, '0', STR_PAD_LEFT);
            Customer::firstOrCreate(['phone' => $phone], ['name' => $name]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static int $phoneCounter = 0;

    private function uniquePhone(): string
    {
        self::$phoneCounter++;
        return '0170' . str_pad((string) (900000 + self::$phoneCounter), 7, '0', STR_PAD_LEFT);
    }

    private function transliterate(string $name): string
    {
        // Simple deterministic slug for email addresses — Arabic branch name → ascii tag.
        static $map = [
            'فرع مدينة نصر' => 'nasrcity', 'فرع المعادي' => 'maadi', 'فرع مصر الجديدة' => 'heliopolis',
            'فرع الإسكندرية' => 'alex2', 'فرع المنصورة' => 'mansoura', 'فرع طنطا' => 'tanta',
            'فرع الغردقة' => 'hurghada', 'فرع أسيوط' => 'assiut',
        ];
        return $map[$name] ?? 'branch' . substr(md5($name), 0, 6);
    }
}
