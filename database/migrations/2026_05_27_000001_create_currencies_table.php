<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('symbol', 10);
            $table->decimal('rate', 10, 4)->default(1.0000)->comment('Exchange rate relative to EGP (1 unit of this currency = rate EGP)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('currencies')->insert([
            ['code' => 'EGP', 'name' => 'الجنيه المصري',   'symbol' => 'ج.م', 'rate' => 1.0000,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'الدولار الأمريكي', 'symbol' => '$',   'rate' => 50.0000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name' => 'اليورو',            'symbol' => '€',   'rate' => 54.0000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SAR', 'name' => 'الريال السعودي',   'symbol' => 'ر.س', 'rate' => 13.3000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
