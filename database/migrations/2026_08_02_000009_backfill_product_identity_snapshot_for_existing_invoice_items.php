<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: every existing invoice_items row gets its product's
 * CURRENT name/sku/barcode frozen in as its permanent display snapshot —
 * this is the last known-correct identity for that historical sale, exactly
 * mirroring how 2026_08_02_000004 froze in the legacy flat selling_price as
 * each pre-existing batch's starting price. Only fills rows where the
 * snapshot is still null, so a re-run (or a partially-applied prior run)
 * never overwrites an already-frozen value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE invoice_items ii
            INNER JOIN products p ON p.id = ii.product_id
            SET
                ii.product_name = p.name,
                ii.product_sku = p.sku,
                ii.product_barcode = p.barcode
            WHERE ii.product_name IS NULL
        ');
    }

    public function down(): void
    {
        // Intentionally left as a no-op — reversing would delete real
        // historical display data, which is itself the exact thing this
        // architecture exists to prevent.
    }
};
