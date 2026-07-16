<?php

namespace App\Console\Commands;

use App\Modules\Hr\Services\SalaryAdvanceService;
use Illuminate\Console\Command;

/**
 * At the start of every month, notify employees + admins about salary-advance
 * installments due THIS calendar month before payroll runs. Idempotent via
 * `reminded_at` — safe to re-run without double-notifying.
 */
class NotifyDueAdvanceInstallments extends Command
{
    protected $signature = 'hr:notify-due-advances';
    protected $description = 'Notify employees + admins about salary-advance installments due this month';

    public function handle(SalaryAdvanceService $advances): int
    {
        $count = $advances->notifyDueInstallments();
        $this->info("Salary advance due-reminders sent: {$count}");

        return self::SUCCESS;
    }
}
