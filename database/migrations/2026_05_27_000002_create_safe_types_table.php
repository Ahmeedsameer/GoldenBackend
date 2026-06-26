<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('kind', ['physical', 'virtual']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // System default — cannot be deleted, required for shop auto-creation
        DB::table('safe_types')->insert([
            'name'       => 'خزنة نقدية',
            'kind'       => 'physical',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_types');
    }
};
