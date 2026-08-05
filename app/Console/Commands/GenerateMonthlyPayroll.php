<?php

namespace App\Console\Commands;

use App\Models\Payroll;
use App\Models\User;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * On the 1st of every month, auto-generate payroll for every active
 * employee for the month that JUST ENDED — a full month of attendance/
 * commission data only exists once the month is over, so "this month" on
 * day 1 means the previous one (the same reasoning payroll teams normally
 * use: pay for the completed period).
 *
 * Idempotent: skips any employee who already has a payroll row for that
 * (year, month) — reuses PayrollService::generate() unchanged, so this
 * never regenerates/deletes an existing payroll the way the admin's manual
 * "regenerate" flow can; it only fills in what's missing. One employee
 * failing never aborts the run for the others.
 */
class GenerateMonthlyPayroll extends Command
{
    protected $signature = 'hr:generate-monthly-payroll';
    protected $description = 'Auto-generate payroll for every active employee for the just-completed month, skipping any that already exist';

    public function handle(PayrollService $payroll): int
    {
        $period = Carbon::now()->subMonthNoOverflow();
        $year = $period->year;
        $month = $period->month;

        $employees = User::whereIn('role', ['manager', 'sales'])->where('status', 'active')->get();
        $existingUserIds = Payroll::where('period_year', $year)->where('period_month', $month)->pluck('user_id')->all();

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($employees as $employee) {
            if (in_array($employee->id, $existingUserIds, true)) {
                $skipped++;
                continue;
            }

            try {
                $payroll->generate($employee, $year, $month);
                $generated++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed for employee #{$employee->id} ({$employee->name}): {$e->getMessage()}");
            }
        }

        $this->info("Monthly payroll generation for {$month}/{$year}: generated={$generated}, skipped(existing)={$skipped}, failed={$failed}");

        return self::SUCCESS;
    }
}
