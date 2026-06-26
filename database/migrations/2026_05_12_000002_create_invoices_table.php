<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->enum('price_type', ['wholesale', 'retail']);
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending');
            $table->decimal('total_amount', 12, 2)->nullable()->comment('Global EGP total entered by the seller; source of the distribution pool');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
