<?php

namespace App\Console\Commands;

use App\Modules\Hr\Services\HrAlertService;
use Illuminate\Console\Command;

/**
 * Daily HR attendance alerts: repeated absences → managers/admins, and any
 * branch whose attendance wasn't completed for the day → admins.
 */
class HrAttendanceAlerts extends Command
{
    protected $signature = 'hr:attendance-alerts';
    protected $description = 'Flag repeated absences and incomplete branch attendance';

    public function handle(HrAlertService $alerts): int
    {
        $absences   = $alerts->repeatedAbsences();
        $incomplete = $alerts->incompleteBranchAttendance();

        $this->info("HR alerts — repeated absences flagged: {$absences}, incomplete branches: {$incomplete}");

        return self::SUCCESS;
    }
}
