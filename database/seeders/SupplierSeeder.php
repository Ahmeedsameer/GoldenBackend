<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            // Fragrance oil importer
            ['name' => 'شركة الشرق للزيوت العطرية',      'phone' => '01100000001'],
            // Oud and incense wholesaler
            ['name' => 'مؤسسة العود والبخور العربي',      'phone' => '01100000002'],
            // Bottle and packaging manufacturer
            ['name' => 'مصنع العبوات الفاخرة',            'phone' => '01100000003'],
            // Chemical carriers and bases supplier
            ['name' => 'شركة القواعد الكيميائية للعطور',   'phone' => '01100000004'],
            // Ready-made perfume importer
            ['name' => 'بيت العطور الدولي للاستيراد',      'phone' => '01100000005'],
        ];

        foreach ($suppliers as $s) {
            Supplier::create($s);
        }
    }
}
