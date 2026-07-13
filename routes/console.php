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
