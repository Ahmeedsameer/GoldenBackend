<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // INTERNAL ONLY — deliberately its own table, never `invoices`/`invoice_items`,
        // so it is structurally impossible for these to leak into Sales/Revenue/
        // Customer/Financial reports, which all query the sales `invoices` table.
        Schema::create('internal_transfer_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('transfer_request_id')->unique()->constrained('transfer_requests')->cascadeOnDelete();
            $table->foreignId('source_shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('destination_shop_id')->constrained('shops')->restrictOnDelete();
            $table->date('date');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('reference_value', 12, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_transfer_invoices');
    }
};
