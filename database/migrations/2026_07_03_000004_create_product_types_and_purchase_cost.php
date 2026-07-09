<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Architectural separation of Product Type (inventory behavior) from Category
 * (classification within a type), plus per-product purchase cost.
 *
 *  - product_types: owns behavior — is_fixed (fixed vs weighted pricing),
 *    default_unit and default thresholds. Seeded: Oil, Bottle, Accessory,
 *    Packaging.
 *  - categories.product_type_id: a category now belongs to a product type
 *    (Oil → Oud/Musk/Amber, Bottle → 30ml/50ml/100ml). minimum_sell_price and
 *    value_percentage stay on the category (genuinely per-classification).
 *  - products.purchase_cost: enables profit = selling_price − purchase_cost and
 *    inventory cost/selling valuation.
 *
 * Backfill preserves current pricing EXACTLY: each existing category is mapped
 * to a type whose is_fixed equals the category's current is_fixed, so the sales
 * engine keeps computing identical prices. Nothing is dropped; FIFO untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique()->comment('oil | bottle | accessory | packaging | …');
            $table->string('name');
            $table->boolean('is_fixed')->default(false)->comment('Fixed selling price (bottle-like) vs weighted pool (oil-like)');
            $table->string('default_unit', 10)->nullable()->comment('g, pcs, ml … default inventory unit for this type');
            $table->decimal('default_warning_quantity', 12, 3)->nullable();
            $table->decimal('default_critical_quantity', 12, 3)->nullable();
            $table->timestamps();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('product_type_id')
                  ->nullable()
                  ->after('value_percentage')
                  ->constrained('product_types')
                  ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_cost', 12, 2)
                  ->nullable()
                  ->after('selling_price')
                  ->comment('Per-product purchase cost; profit = selling_price − purchase_cost');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();

        // 1) Seed the four initial product types.
        $types = [
            ['code' => 'oil',       'name' => 'زيوت',   'is_fixed' => 0, 'default_unit' => 'g',   'default_warning_quantity' => 200, 'default_critical_quantity' => 50],
            ['code' => 'bottle',    'name' => 'زجاجات', 'is_fixed' => 1, 'default_unit' => 'pcs', 'default_warning_quantity' => 20,  'default_critical_quantity' => 5],
            ['code' => 'accessory', 'name' => 'إكسسوارات', 'is_fixed' => 0, 'default_unit' => 'pcs', 'default_warning_quantity' => 20, 'default_critical_quantity' => 5],
            ['code' => 'packaging', 'name' => 'تغليف',  'is_fixed' => 1, 'default_unit' => 'pcs', 'default_warning_quantity' => 50,  'default_critical_quantity' => 10],
        ];
        foreach ($types as &$t) { $t['created_at'] = $now; $t['updated_at'] = $now; }
        DB::table('product_types')->insert($types);

        $typeId = fn (string $code) => DB::table('product_types')->where('code', $code)->value('id');
        $oilId = $typeId('oil'); $bottleId = $typeId('bottle');
        $accId = $typeId('accessory'); $pkgId = $typeId('packaging');

        // 2) Map every existing category to a type whose is_fixed matches, so the
        //    engine keeps reading the same behavior. Name heuristics only pick the
        //    *label*; is_fixed parity is what preserves pricing.
        foreach (DB::table('categories')->get() as $cat) {
            $name = $cat->name ?? '';
            if ((int) $cat->is_fixed === 1) {
                // fixed → Bottle unless it clearly reads as packaging
                $target = preg_match('/تغليف|packag/iu', $name) ? $pkgId : $bottleId;
            } else {
                // weighted → Oil when it reads as oil, else Accessory
                $target = preg_match('/زيت|زيوت|oil/iu', $name) ? $oilId : $accId;
            }
            DB::table('categories')->where('id', $cat->id)->update(['product_type_id' => $target]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchase_cost');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_type_id');
        });
        Schema::dropIfExists('product_types');
    }
};
