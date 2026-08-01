<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch Deletion Protection:
 *  1. `supply_items.archived_at` — the ONLY supported way to retire a batch
 *     from future sale (hides it from FIFO/pricing going forward). A batch
 *     is NEVER physically deleted once it exists in history.
 *  2. Hardens `invoice_items.supply_item_id` from ON DELETE SET NULL to ON
 *     DELETE RESTRICT — a hard database-level guarantee (on top of the
 *     application-level check in SupplyService::delete()) that a batch
 *     referenced by any invoice line can never be deleted, full stop, even
 *     via a raw query that bypasses application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('priced_by');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['supply_item_id']);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreign('supply_item_id')->references('id')->on('supply_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['supply_item_id']);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreign('supply_item_id')->references('id')->on('supply_items')->nullOnDelete();
        });

        Schema::table('supply_items', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
