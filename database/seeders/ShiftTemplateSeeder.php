<?php

namespace Database\Seeders;

use App\Models\ShiftTemplate;
use Illuminate\Database\Seeder;

class ShiftTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'الشيفت الصباحي', 'start_time' => '09:00', 'end_time' => '17:00'],
            ['name' => 'الشيفت المسائي', 'start_time' => '14:00', 'end_time' => '22:00'],
            ['name' => 'الشيفت الليلي',  'start_time' => '22:00', 'end_time' => '06:00'],
        ];

        foreach ($defaults as $d) {
            ShiftTemplate::firstOrCreate(['name' => $d['name']], $d);
        }
    }
}
