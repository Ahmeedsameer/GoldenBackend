<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — employee ↔ branch assignments (many-to-many).
 *
 * An employee can work in one or more branches. Each assignment carries the
 * BRANCH commission percentage that belongs to that employee INSIDE that
 * specific branch. Commissions are never mixed between branches — the invoice
 * branch (shop_id) is always the source of truth.
 *
 * The users.shop_id column is kept as the employee's "primary" branch for
 * backward compatibility with existing cashier/inventory scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->decimal('branch_commission_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branches');
    }
};
