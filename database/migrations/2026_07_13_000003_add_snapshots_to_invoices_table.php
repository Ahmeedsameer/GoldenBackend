<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Employee-transfer architecture, part 3 — immutable invoice snapshots.
 *
 * Invoices are permanent financial records. Each invoice permanently stores the
 * seller's name/email and the branch's name AS THEY WERE when the invoice was
 * created, so historical invoices stay accurate even after the employee is
 * transferred, renamed, or the branch is renamed.
 *
 * seller_id / shop_id (FKs) are unchanged; these are additive snapshot columns.
 * Existing invoices are backfilled from their current seller/shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('seller_name')->nullable()->after('seller_id');
            $table->string('seller_email')->nullable()->after('seller_name');
            $table->string('branch_name')->nullable()->after('shop_id');
        });

        // Backfill existing invoices from current related records.
        DB::statement("
            UPDATE invoices i
            LEFT JOIN users u ON u.id = i.seller_id
            LEFT JOIN shops s ON s.id = i.shop_id
            SET i.seller_name = u.name,
                i.seller_email = u.email,
                i.branch_name  = s.name
            WHERE i.seller_name IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['seller_name', 'seller_email', 'branch_name']);
        });
    }
};
