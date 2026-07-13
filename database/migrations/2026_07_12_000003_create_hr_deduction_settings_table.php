<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — configurable salary-deduction rules.
 *
 * Deduction values are NEVER hardcoded. Each rule is keyed by a stable code
 * (absence, half_day, late, unpaid_leave). The payroll engine looks each rule
 * up by code and applies it.
 *
 * mode:
 *   - 'daily_fraction' → value is a fraction of the employee's daily rate
 *                        (daily rate = base_salary / working_days_in_month).
 *                        e.g. absence = 1.0 (a full day), half_day = 0.5.
 *   - 'fixed'          → value is a flat currency amount per occurrence
 *                        (e.g. a late-arrival fee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_deduction_settings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // absence | half_day | late | unpaid_leave
            $table->string('label');
            $table->string('mode')->default('daily_fraction'); // daily_fraction | fixed
            $table->decimal('value', 12, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_deduction_settings');
    }
};
