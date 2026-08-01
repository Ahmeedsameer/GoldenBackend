<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: every existing invoice_items row already has unit_cost
 * (see 2026_08_02_000004) and price/quantity from day one, so line_cost/
 * line_profit/supply_item_id can be derived exactly — this is not an
 * estimate, it reproduces precisely what the sale actually charged and cost.
 * After this runs, every row is a complete, permanent snapshot; nothing here
 * needs to be (or ever should be) recomputed from live data again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE invoice_items ii
            INNER JOIN goods g ON g.id = ii.goods_id
            SET ii.supply_item_id = g.supply_item_id
            WHERE ii.supply_item_id IS NULL
        ');

        DB::statement('
            UPDATE invoice_items
            SET line_cost = ROUND(unit_cost * quantity, 2)
            WHERE line_cost IS NULL AND unit_cost IS NOT NULL
        ');

        DB::statement('
            UPDATE invoice_items
            SET line_profit = ROUND((price * quantity) - line_cost, 2)
            WHERE line_profit IS NULL AND line_cost IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Intentionally left as a no-op — reversing would mean deleting real
        // accounting snapshot data, which is itself the exact thing this
        // architecture exists to prevent.
    }
};
