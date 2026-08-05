<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Tags — 'auto' tags are rule-computed on the fly (see
 * CustomerTagService::computeAutoTags()) and never persisted per-customer,
 * so this table only stores their fixed definitions (name/color) once,
 * seeded below. 'manual' tags are freely created by Admin/Manager and
 * assigned to customers via the customer_tag pivot (next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 20); // maps to app-badge's BadgeColor values
            $table->enum('type', ['auto', 'manual']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Fixed automatic-tag definitions — matched by slug in
        // CustomerTagService::computeAutoTags(). Colors picked to be visually
        // distinct across app-badge's 7-color palette.
        $now = now();
        $autoTags = [
            ['name' => 'VIP',                 'slug' => 'vip',                 'color' => 'warning'],
            ['name' => 'عميل منتظم',           'slug' => 'regular-customer',    'color' => 'info'],
            ['name' => 'بيع بالجملة',          'slug' => 'wholesale',           'color' => 'dark'],
            ['name' => 'بيع بالتجزئة',         'slug' => 'retail',              'color' => 'light'],
            ['name' => 'غير نشط',              'slug' => 'inactive',            'color' => 'error'],
            ['name' => 'عميل جديد',            'slug' => 'new-customer',        'color' => 'primary'],
            ['name' => 'عميل بإنفاق مرتفع',    'slug' => 'high-spender',        'color' => 'success'],
            ['name' => 'يشتري بشكل متكرر',     'slug' => 'frequently-buying',   'color' => 'info'],
        ];
        foreach ($autoTags as $tag) {
            $tag['type'] = 'auto';
            $tag['created_at'] = $now;
            $tag['updated_at'] = $now;
            \Illuminate\Support\Facades\DB::table('tags')->insert($tag);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
