<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the single mutable customers.notes field with an append-only
 * history. The legacy notes/notes_updated_by/notes_updated_at columns and
 * the PUT /customers/{id}/notes route are left completely untouched
 * (existing API preserved) — the frontend simply stops writing to them and
 * reads/writes this table instead. Any note that already existed is
 * backfilled below as history entry #1, so nothing written before this
 * migration is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();

            $table->index('customer_id');
        });

        $existing = DB::table('customers')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->get(['id', 'notes', 'notes_updated_by', 'notes_updated_at', 'created_at']);

        foreach ($existing as $customer) {
            $timestamp = $customer->notes_updated_at ?? $customer->created_at ?? now();
            DB::table('customer_notes')->insert([
                'customer_id' => $customer->id,
                'author_id'   => $customer->notes_updated_by,
                'note'        => $customer->notes,
                'created_at'  => $timestamp,
                'updated_at'  => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
