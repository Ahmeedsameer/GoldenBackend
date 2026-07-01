<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conventions', function (Blueprint $table) {
            $table->id();

            // the cash advance (عهدة) amount assigned to the branch
            $table->decimal('amount', 12, 2)->default(0);

            // the admin who owns / assigned the convention
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();

            // the branch the convention belongs to
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conventions');
    }
};
