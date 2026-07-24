<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 — the Assemble-on-Sale "Alcohol" picker (used exactly like the Oil
 * picker) needs its own precise category, distinct from "قواعد وحوامل" (Bases
 * & Carriers), which currently mixes real alcohol with DPG/jojoba/IPM/neutral
 * base — none of those are alcohol and must never appear in the Alcohol
 * picker. This creates a dedicated "كحول" category (same product_type as its
 * former parent bucket — pricing behavior is unchanged, only the grouping is
 * more precise) and moves the existing alcohol product into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $basesCategory = DB::table('categories')->where('name', 'قواعد وحوامل')->first();
        if (! $basesCategory) {
            return;
        }

        $alcoholCategoryId = DB::table('categories')->insertGetId([
            'name' => 'كحول',
            'description' => 'الكحول العطري المستخدم كمذيب في تركيب العطور — مستقل عن باقي القواعد والحوامل.',
            'product_type_id' => $basesCategory->product_type_id,
            'is_fixed' => $basesCategory->is_fixed,
            'value_percentage' => $basesCategory->value_percentage,
            'default_warning_quantity' => $basesCategory->default_warning_quantity,
            'default_critical_quantity' => $basesCategory->default_critical_quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')
            ->where('category_id', $basesCategory->id)
            ->where('name', 'like', '%كحول%')
            ->update(['category_id' => $alcoholCategoryId]);
    }

    public function down(): void
    {
        $basesCategory = DB::table('categories')->where('name', 'قواعد وحوامل')->first();
        $alcoholCategory = DB::table('categories')->where('name', 'كحول')->first();

        if ($basesCategory && $alcoholCategory) {
            DB::table('products')
                ->where('category_id', $alcoholCategory->id)
                ->update(['category_id' => $basesCategory->id]);
        }

        if ($alcoholCategory) {
            DB::table('categories')->where('id', $alcoholCategory->id)->delete();
        }
    }
};
