<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill, same pattern as 2026_08_02_000009 — freezes in each
 * existing composed-sale line's parent product's CURRENT name as its
 * permanent snapshot. Only rows with parent_product_id set are touched;
 * only where the snapshot is still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE invoice_items ii
            INNER JOIN products p ON p.id = ii.parent_product_id
            SET ii.parent_product_name = p.name
            WHERE ii.parent_product_id IS NOT NULL AND ii.parent_product_name IS NULL
        ');
    }

    public function down(): void
    {
        // Intentionally left as a no-op — see 2026_08_02_000009 for the same rationale.
    }
};
