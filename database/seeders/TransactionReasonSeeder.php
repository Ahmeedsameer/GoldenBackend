<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionReasonSeeder extends Seeder
{
    /**
     * Seeds common transaction reasons for manual safe operations.
     */
    public function run(): void
    {
        $reasons = [
            // Inbound
            ['name' => 'إيداع نقدي',        'direction' => 'in',   'is_active' => true],
            ['name' => 'استرداد بضاعة',     'direction' => 'in',   'is_active' => true],
            ['name' => 'تحويل وارد',         'direction' => 'in',   'is_active' => true],

            // Outbound
            ['name' => 'مصاريف إدارية',     'direction' => 'out',  'is_active' => true],
            ['name' => 'مشتريات',           'direction' => 'out',  'is_active' => true],
            ['name' => 'سحب نقدي',          'direction' => 'out',  'is_active' => true],
            ['name' => 'تحويل صادر',        'direction' => 'out',  'is_active' => true],
            ['name' => 'مصاريف صيانة',      'direction' => 'out',  'is_active' => true],

            // Both
            ['name' => 'تسوية',             'direction' => 'both', 'is_active' => true],
            ['name' => 'تعديل يدوي',        'direction' => 'both', 'is_active' => true],
        ];

        $now = now();

        foreach ($reasons as &$reason) {
            $reason['created_at'] = $now;
            $reason['updated_at'] = $now;
        }

        DB::table('transaction_reasons')->insert($reasons);
    }
}
