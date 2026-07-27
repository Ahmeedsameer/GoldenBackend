<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Methods — admin-managed, unlimited (Cash EGP, Cash USD, Visa CIB,
 * Vodafone Cash, InstaPay, Fawry, ...). Replaces the old hardcoded
 * App\Modules\Sales\Enums\PaymentMethod (which only ever had 'cash'/'visa')
 * as the source of truth for what a cashier can select — that enum file is
 * left in place, unused, for historical string values already stored on
 * old invoice_payments rows.
 *
 * `processing_fee_percent` is only meaningful when `type` is a card type
 * (visa/mastercard/bank_card) — enforced at the application layer, not the
 * schema, so an admin can still leave it null/0 for any type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['cash', 'visa', 'mastercard', 'bank_card', 'mobile_wallet', 'bank_transfer', 'other']);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('processing_fee_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the two methods that already existed as hardcoded enum values,
        // so nothing that relied on "cash"/"visa" existing goes blank on deploy.
        $egpId = DB::table('currencies')->where('code', 'EGP')->value('id');
        if ($egpId) {
            DB::table('payment_methods')->insert([
                ['name' => 'نقدي', 'type' => 'cash', 'currency_id' => $egpId, 'processing_fee_percent' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'فيزا', 'type' => 'visa', 'currency_id' => $egpId, 'processing_fee_percent' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
