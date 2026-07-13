<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — audit log.
 *
 * Every HR action (employee created, salary/commission changed, attendance
 * edited, leave approved/rejected, payroll generated, role/branch changed) is
 * recorded here with actor, action, target, old/new values and IP address.
 * Polymorphic subject so any HR entity can be referenced without extra tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject'); // subject_type + subject_id
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_logs');
    }
};
