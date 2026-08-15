<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_channels', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->unique(); // facebook, instagram, whatsapp, tiktok, youtube
            $table->string('label');
            // URL for facebook/instagram/tiktok/youtube; local phone number for whatsapp.
            $table->string('value')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('social_channels')->insert([
            ['platform' => 'facebook',  'label' => 'Facebook',  'value' => null, 'is_enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'instagram', 'label' => 'Instagram', 'value' => null, 'is_enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'whatsapp',  'label' => 'WhatsApp',  'value' => null, 'is_enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'tiktok',    'label' => 'TikTok',    'value' => null, 'is_enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'youtube',   'label' => 'YouTube',   'value' => null, 'is_enabled' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('social_channels');
    }
};
