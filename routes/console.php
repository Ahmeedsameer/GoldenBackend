<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HR — activate/complete employee transfers whose dates are due (daily).
Schedule::command('hr:process-transfers')->dailyAt('00:05');

// HR — repeated-absence + incomplete-attendance alerts (daily, end of day).
Schedule::command('hr:attendance-alerts')->dailyAt('20:00');

// HR — notify employees + admins about salary-advance installments due this month.
Schedule::command('hr:notify-due-advances')->monthlyOn(1, '06:00');

// HR — auto-generate payroll for every active employee for the just-completed
// month. Runs after the advance-due-reminder above so a due installment is
// already visible before that month's payroll folds its deduction in.
Schedule::command('hr:generate-monthly-payroll')->monthlyOn(1, '00:30');
