<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            // $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->float('minimum_sell_price')->default(0.00);
            $table->boolean('is_fixed')->default(false)->comment('If true, unit price = category minimum_sell_price; no pool distribution');
            $table->decimal('value_percentage', 5, 2)->nullable()->comment('Weight multiplier 0-100 used in pool distribution for non-fixed categories');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
