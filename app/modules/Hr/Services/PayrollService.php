<?php

namespace App\Modules\Hr\Services;

use App\Models\HrDeductionSetting;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable monthly payroll generation.
 *
 *   Final Salary = Base Salary
 *                + Personal Sales Commission
 *                + Branch Bonus (equal share of branch pools)
 *                − Deductions (absence / late / half-day / unpaid leave)
 *
 * Salary + percentages are snapshotted onto the payroll row; the full breakdown
 * is stored as immutable payroll_lines. A locked payroll can never be
 * regenerated. Deduction values are read from hr_deduction_settings (never
 * hardcoded); the daily rate = base_salary / days-in-month.
 */
class PayrollService
{
    public function __construct(
        private CommissionService $commissions,
        private AttendanceService $attendance,
        private NotificationService $notifications,
    ) {}

    /**
     * Generate (or regenerate, if unlocked) a payroll for one employee for the
     * given month. Returns the immutable Payroll with its lines.
     */
    public function generate(User $employee, int $year, int $month): Payroll
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to   = $from->copy()->endOfMonth();

        $existing = Payroll::where('user_id', $employee->id)
            ->where('period_year', $year)->where('period_month', $month)->first();

        if ($existing && $existing->is_locked) {
            throw ValidationException::withMessages(['payroll' => 'كشف الراتب لهذا الشهر مقفول ولا يمكن إعادة توليده.']);
        }

        return DB::transaction(function () use ($employee, $year, $month, $from, $to, $existing) {
            // Remove a prior unlocked payroll (regeneration).
            if ($existing) {
                $existing->lines()->delete();
                $existing->delete();
            }

            // ── Earnings ──────────────────────────────────────────────────────
            $base     = (float) $employee->base_salary;
            $personal = $this->commissions->personalCommission($employee, $from, $to);
            $branch   = $this->commissions->branchCommission($employee, $from, $to);

            // ── Attendance + unpaid leave (this month) ────────────────────────
            $att        = $this->attendance->summary($employee->id, $from, $to);
            $unpaidDays = (int) LeaveRequest::where('user_id', $employee->id)
                ->where('status', LeaveRequest::APPROVED)
                ->whereYear('start_date', $year)->whereMonth('start_date', $month)
                ->sum('unpaid_days');

            // ── Deductions (configurable) ─────────────────────────────────────
            $workingDays = $from->daysInMonth;
            $dailyRate   = $workingDays > 0 ? $base / $workingDays : 0.0;
            $settings    = HrDeductionSetting::where('is_active', true)->get()->keyBy('code');

            $deductionSpec = [
                'absence'      => $att['absent'],
                'late'         => $att['late'],
                'half_day'     => $att['half_day'],
                'unpaid_leave' => $unpaidDays,
            ];

            $deductionLines   = [];
            $totalDeductions  = 0.0;
            foreach ($deductionSpec as $code => $qty) {
                if ($qty <= 0 || ! isset($settings[$code])) {
                    continue;
                }
                $rule   = $settings[$code];
                $per    = $rule->mode === HrDeductionSetting::MODE_DAILY_FRACTION
                    ? (float) $rule->value * $dailyRate
                    : (float) $rule->value;
                $amount = round($per * $qty, 2);
                if ($amount <= 0) {
                    continue;
                }
                $totalDeductions += $amount;
                $deductionLines[] = [
                    'type'   => PayrollLine::DEDUCTION,
                    'label'  => $rule->label,
                    'amount' => -$amount,
                    'meta'   => ['code' => $code, 'qty' => $qty, 'per_unit' => round($per, 2), 'mode' => $rule->mode, 'value' => (float) $rule->value],
                ];
            }

            $gross = round($base + $personal['amount'] + $branch['total'], 2);
            $net   = round($gross - $totalDeductions, 2);

            // ── Persist immutable payroll ─────────────────────────────────────
            $payroll = Payroll::create([
                'user_id'                     => $employee->id,
                'period_year'                 => $year,
                'period_month'                => $month,
                'base_salary'                 => $base,
                'personal_commission_percent' => $employee->personal_commission_percent,
                'personal_sales_total'        => $personal['sales_total'],
                'personal_commission_amount'  => $personal['amount'],
                'branch_commission_amount'    => $branch['total'],
                'gross'                       => $gross,
                'total_deductions'            => round($totalDeductions, 2),
                'net_salary'                  => $net,
                'working_days'                => $workingDays,
                'present_days'                => $att['present'],
                'absent_days'                 => $att['absent'],
                'late_days'                   => $att['late'],
                'half_days'                   => $att['half_day'],
                'unpaid_leave_days'           => $unpaidDays,
                'status'                      => Payroll::GENERATED,
                'generated_by'                => auth()->id(),
                'generated_at'                => now(),
            ]);

            // Lines: base, personal commission, branch bonus (per segment), deductions.
            $lines = [[
                'type' => PayrollLine::BASE, 'label' => 'الراتب الأساسي', 'amount' => $base, 'meta' => null,
            ]];
            if ($personal['amount'] > 0) {
                $lines[] = ['type' => PayrollLine::PERSONAL_COMMISSION, 'label' => 'عمولة المبيعات الشخصية',
                    'amount' => $personal['amount'], 'meta' => ['percent' => $personal['percent'], 'sales' => $personal['sales_total']]];
            }
            foreach ($branch['segments'] as $seg) {
                $lines[] = ['type' => PayrollLine::BRANCH_COMMISSION, 'label' => 'بونص الفرع', 'shop_id' => $seg['shop_id'],
                    'amount' => $seg['share'], 'meta' => $seg];
            }
            $lines = array_merge($lines, $deductionLines);

            foreach ($lines as $l) {
                $payroll->lines()->create($l);
            }

            // Notify the employee their payroll is ready.
            $this->notifications->notify([$employee->id], 'payroll', 'تم إصدار كشف راتبك',
                "كشف راتب {$month}/{$year}: صافي " . number_format($net, 2) . " ج.م", ['type' => 'payroll', 'payroll_id' => $payroll->id]);

            if ($totalDeductions > 0) {
                $this->notifications->notify([$employee->id], 'payroll', 'خصومات على الراتب',
                    "طُبّقت خصومات بقيمة " . number_format($totalDeductions, 2) . " ج.م على راتب {$month}/{$year}.", ['type' => 'payroll']);
            }

            return $payroll->load('lines');
        });
    }

    /** Generate payroll for every active employee for the month. */
    public function generateAll(int $year, int $month): array
    {
        $employees = User::whereIn('role', ['manager', 'sales'])->where('status', 'active')->get();
        $done = 0;
        foreach ($employees as $e) {
            $this->generate($e, $year, $month);
            $done++;
        }
        $this->notifications->notifyAdmins('payroll', 'اكتمل توليد كشوف الرواتب',
            "تم توليد كشوف رواتب {$month}/{$year} لعدد {$done} موظف.", ['type' => 'payroll']);

        return ['generated' => $done, 'year' => $year, 'month' => $month];
    }

    public function lock(Payroll $payroll): Payroll
    {
        $payroll->update(['is_locked' => true, 'locked_by' => auth()->id(), 'locked_at' => now()]);
        return $payroll;
    }

    public function unlock(Payroll $payroll): Payroll
    {
        $payroll->update(['is_locked' => false, 'locked_by' => null, 'locked_at' => null]);
        return $payroll;
    }

    public function markPaid(Payroll $payroll): Payroll
    {
        $payroll->update(['status' => Payroll::PAID]);
        return $payroll;
    }
}
