<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['counting', 'review', 'approved', 'completed'])->default('counting');
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_session_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_count_session_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreign('inventory_count_session_id', 'ics_employees_session_fk')
                ->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
            $table->unique(['inventory_count_session_id', 'user_id'], 'count_session_user_unique');
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_count_session_id');
            $table->foreign('inventory_count_session_id', 'ics_items_session_fk')
                ->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('system_quantity', 12, 3);
            $table->decimal('physical_quantity', 12, 3)->nullable();
            $table->decimal('difference', 12, 3)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->foreign('inventory_count_session_id', 'iar_count_session_fk')
                ->references('id')->on('inventory_count_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->dropForeign('iar_count_session_fk');
        });
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_count_session_employees');
        Schema::dropIfExists('inventory_count_sessions');
    }
};
