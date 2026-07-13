<?php

namespace Database\Seeders;

use App\Models\HrDeductionSetting;
use Illuminate\Database\Seeder;

/**
 * Default (configurable) salary-deduction rules. Admin can edit the values
 * later; nothing is hardcoded in the payroll engine.
 */
class HrDeductionSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'absence',      'label' => 'خصم غياب',          'mode' => 'daily_fraction', 'value' => 1.0],
            ['code' => 'half_day',     'label' => 'خصم نصف يوم',       'mode' => 'daily_fraction', 'value' => 0.5],
            ['code' => 'late',         'label' => 'خصم تأخير',         'mode' => 'daily_fraction', 'value' => 0.25],
            ['code' => 'unpaid_leave', 'label' => 'خصم إجازة بدون أجر', 'mode' => 'daily_fraction', 'value' => 1.0],
        ];

        foreach ($defaults as $d) {
            HrDeductionSetting::firstOrCreate(['code' => $d['code']], $d);
        }
    }
}
