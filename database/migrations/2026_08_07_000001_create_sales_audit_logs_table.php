<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales — audit log. Independent of hr_audit_logs (see HrAuditLog) — same
 * shape (actor, action, polymorphic subject, old/new JSON, IP), kept as its
 * own table so the Sales and HR modules stay fully decoupled.
 *
 * Adds one thing HR's log doesn't need: a denormalized `customer_id`. Sales
 * events relate to a customer indirectly (through an invoice or a note), not
 * directly as the subject, so without this column building one customer's
 * activity timeline would mean N separate polymorphic lookups. With it, it's
 * a single indexed `WHERE customer_id = ?`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject'); // subject_type + subject_id
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_audit_logs');
    }
};
