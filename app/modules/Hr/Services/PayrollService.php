<?php

namespace App\Modules\Hr\Services;

use App\Models\HrDeductionSetting;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Safe;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use App\Modules\Safe\Services\SafeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable monthly payroll generation.
 *
 *   Final Salary = Base Salary
 *                + Personal Sales Commission
 *                + Branch Bonus (equal share of branch pools)
 *                + Manual Bonuses
 *                − Manual Penalties
 *                − Automatic Attendance Deductions (absence / late / half-day)
 *                − Unpaid Leave Deductions
 *                − Salary Advance Installment (if one is due this month)
 *
 * Salary + percentages are snapshotted onto the payroll row; the full breakdown
 * is stored as immutable payroll_lines. A locked payroll can never be
 * regenerated. Deduction values are read from hr_deduction_settings (never
 * hardcoded); the daily rate = base_salary / days-in-month. Bonuses/Penalties
 * are read live from the `bonuses`/`penalties` tables (never duplicated
 * anywhere else) at generation time — exactly like commission and branch
 * bonus — so editing a deduction rule or adding a bonus never rewrites an
 * already-generated (and especially a locked) payroll.
 */
class PayrollService
{
    public function __construct(
        private CommissionService $commissions,
        private AttendanceService $attendance,
        private NotificationService $notifications,
        private BonusPenaltyService $bonusPenalty,
        private SalaryAdvanceService $salaryAdvance,
        private SafeService $safes,
    ) {}

    /** Company-wide default currency for salary payments — same fallback SalaryAdvanceService/SalesService already use. */
    private function defaultCurrencyId(): int
    {
        return \App\Models\Currency::where('code', 'EGP')->value('id');
    }

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

            // ── Earnings, attendance, deductions, bonuses/penalties ────────────
            // Shared with settlementPreview() below — a full calendar month here,
            // never duplicated elsewhere.
            $c = $this->computeComponents($employee, $from, $to, (float) $employee->base_salary);
            $base         = $c['base'];
            $personal     = $c['personal'];
            $branch       = $c['branch'];
            $att          = $c['attendance'];
            $unpaidDays   = $c['unpaidDays'];
            $workingDays  = $c['daysInMonth'];
            $deductionLines  = $c['deductionLines'];
            $totalDeductions = $c['totalDeductions'];
            $bonuses      = $c['bonuses'];
            $penalties    = $c['penalties'];
            $bonusTotal   = $c['bonusTotal'];
            $penaltyTotal = $c['penaltyTotal'];

            // ── Salary Advance installment (this month, if any is due) ────────
            $advanceHit = $this->salaryAdvance->deductForPayroll($employee, $year, $month);
            $advanceDeduction = $advanceHit ? (float) $advanceHit['installment']->planned_amount : 0.0;
            $totalDeductions += $advanceDeduction;

            $gross = $c['gross'];
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
                'bonus_total'                 => $bonusTotal,
                'penalty_total'               => $penaltyTotal,
                'advance_deduction'           => $advanceDeduction,
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
            foreach ($bonuses as $b) {
                $lines[] = ['type' => PayrollLine::BONUS, 'label' => $b->reason, 'amount' => (float) $b->amount,
                    'meta' => ['bonus_id' => $b->id, 'date' => $b->date->toDateString(), 'notes' => $b->notes]];
            }
            foreach ($penalties as $p) {
                $lines[] = ['type' => PayrollLine::PENALTY, 'label' => $p->reason, 'amount' => -(float) $p->amount,
                    'meta' => ['penalty_id' => $p->id, 'date' => $p->date->toDateString(), 'notes' => $p->notes]];
            }
            if ($advanceHit) {
                $lines[] = ['type' => PayrollLine::ADVANCE, 'label' => 'قسط سلفة راتب', 'amount' => -$advanceDeduction,
                    'meta' => ['advance_id' => $advanceHit['advance']->id, 'installment_id' => $advanceHit['installment']->id,
                        'remaining_after' => round($advanceHit['advance']->remaining_balance - $advanceDeduction, 2)]];
            }
            $lines = array_merge($lines, $deductionLines);

            foreach ($lines as $l) {
                $payroll->lines()->create($l);
            }

            // Finalize the advance installment now that the Payroll row exists (needs its id).
            if ($advanceHit) {
                $this->salaryAdvance->markInstallmentDeducted($advanceHit['installment'], $payroll);
            }

            // Notify the employee their payroll is ready.
            $this->notifications->notify([$employee->id], 'payroll', 'تم إصدار كشف راتبك',
                "كشف راتب {$month}/{$year}: صافي " . number_format($net, 2) . " ج.م",
                ['type' => 'payroll', 'payroll_id' => $payroll->id, 'route' => '/dashboard/my-profile']);

            if ($totalDeductions > 0) {
                $this->notifications->notify([$employee->id], 'payroll', 'خصومات على الراتب',
                    "طُبّقت خصومات بقيمة " . number_format($totalDeductions, 2) . " ج.م على راتب {$month}/{$year}.",
                    ['type' => 'payroll', 'payroll_id' => $payroll->id, 'route' => '/dashboard/my-profile']);
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

    /**
     * @deprecated kept only in case another caller still binds to it — pay()
     * below is the real implementation now (moves real money through Safe).
     */
    public function markPaid(Payroll $payroll): Payroll
    {
        $payroll->update(['status' => Payroll::PAID]);
        return $payroll;
    }

    /**
     * Pay a salary — moves `net_salary` out of the chosen Safe (guarded
     * against overdraft, exactly like every other Safe outflow), marks the
     * Payroll `paid` + locked (a paid payroll can never be edited/regenerated
     * again — closing the one gap `is_locked` alone didn't cover), and — if
     * this month's payroll included a salary-advance installment deduction —
     * credits that installment's amount back into the advance's repayment
     * safe. That credit is deliberately fired HERE (at payment time), not at
     * generation time: the money the company keeps by paying the employee
     * less only becomes a real, final event once the (now-reduced) salary
     * has actually been paid out; before that, the payroll could still be
     * regenerated.
     */
    public function pay(Payroll $payroll, int $safeId, User $actor, ?string $note = null): Payroll
    {
        if ($payroll->status === Payroll::PAID) {
            throw ValidationException::withMessages(['payroll' => 'كشف الراتب هذا مدفوع بالفعل.']);
        }

        return DB::transaction(function () use ($payroll, $safeId, $actor, $note) {
            $safe = Safe::where('is_active', true)->findOrFail($safeId);
            $currencyId = $this->defaultCurrencyId();

            // A zero/negative net salary (deductions met or exceeded earnings)
            // means there is nothing to actually pay out — recordSalaryPayment()
            // would otherwise apply a negative amount and corrupt the safe's
            // balance (a negative 'out' transaction nets as an inflow). Skip the
            // money movement entirely; the payroll still gets marked paid+locked below.
            if ((float) $payroll->net_salary > 0) {
                $this->safes->recordSalaryPayment(
                    $safe, $payroll, $currencyId, (float) $payroll->net_salary, $actor->id,
                    $note ?? "صرف راتب الموظف {$payroll->user->name} — {$payroll->period_month}/{$payroll->period_year}",
                );
            }

            $payroll->update([
                'status'         => Payroll::PAID,
                'paying_safe_id' => $safe->id,
                'paid_by'        => $actor->id,
                'paid_at'        => now(),
                'is_locked'      => true,
                'locked_by'      => $payroll->locked_by ?? $actor->id,
                'locked_at'      => $payroll->locked_at ?? now(),
            ]);

            // If this payroll deducted a salary-advance installment, the safe
            // that "recovers" it defaults to the advance's own default
            // repayment safe (see SalaryAdvanceService::changeDefaultSafe()) —
            // falling back to its disbursement safe for advances approved
            // before that default existed.
            $installment = SalaryAdvanceInstallment::where('payroll_id', $payroll->id)->first();
            if ($installment && (float) $installment->planned_amount > 0) {
                $advance = $installment->advance;
                $repaymentSafeId = $advance->default_repayment_safe_id ?? $advance->paying_safe_id;
                if ($repaymentSafeId) {
                    $this->safes->recordAdvanceRepayment(
                        Safe::findOrFail($repaymentSafeId), $advance, $currencyId, (float) $installment->planned_amount, $actor->id,
                        "استرداد قسط سلفة الموظف {$advance->user->name} عبر الراتب — {$payroll->period_month}/{$payroll->period_year}",
                    );
                }
            }

            return $payroll->fresh(['user', 'payingSafe.shop', 'paidBy']);
        });
    }

    /**
     * Pay every PENDING (status=generated) payroll matching the given
     * filters from one Safe. Each employee's pay() opens its OWN
     * DB::transaction — so one employee failing (e.g. insufficient balance)
     * never rolls back the others already paid in this run.
     *
     * @param  array{year?:int, month?:int, shop_id?:int, user_id?:int}  $filters
     * @return array{paid: array<int, array{payroll_id:int, employee:string}>, failed: array<int, array{payroll_id:int, employee:string, reason:string}>}
     */
    public function payAll(array $filters, int $safeId, User $actor): array
    {
        $query = Payroll::with('user:id,name')->where('status', Payroll::GENERATED);
        if (! empty($filters['year']))  $query->where('period_year', (int) $filters['year']);
        if (! empty($filters['month'])) $query->where('period_month', (int) $filters['month']);
        if (! empty($filters['user_id'])) $query->where('user_id', (int) $filters['user_id']);
        if (! empty($filters['shop_id'])) {
            $query->whereHas('user', fn ($q) => $q->where('shop_id', (int) $filters['shop_id']));
        }

        $payrolls = $query->get();
        $paid = [];
        $failed = [];

        foreach ($payrolls as $payroll) {
            try {
                $this->pay($payroll, $safeId, $actor);
                $paid[] = ['payroll_id' => $payroll->id, 'employee' => $payroll->user->name];
            } catch (\Throwable $e) {
                $failed[] = ['payroll_id' => $payroll->id, 'employee' => $payroll->user->name, 'reason' => $e->getMessage()];
            }
        }

        return ['paid' => $paid, 'failed' => $failed];
    }

    /**
     * Commission / branch bonus / attendance-deductions / bonuses & penalties
     * for one employee over [$from, $to] — the exact math generate() has always
     * used, extracted so it's computed in exactly one place. $base is passed in
     * (rather than always read from $employee->base_salary) so a Final
     * Settlement can substitute a Leaving-Date-prorated salary while every
     * other component still runs unchanged.
     */
    private function computeComponents(User $employee, Carbon $from, Carbon $to, float $base): array
    {
        $personal = $this->commissions->personalCommission($employee, $from, $to);
        $branch   = $this->commissions->branchCommission($employee, $from, $to);

        $att        = $this->attendance->summary($employee->id, $from, $to);
        $unpaidDays = (int) LeaveRequest::where('user_id', $employee->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereYear('start_date', $from->year)->whereMonth('start_date', $from->month)
            ->sum('unpaid_days');

        $daysInMonth = $from->daysInMonth;
        $dailyRate   = $daysInMonth > 0 ? $base / $daysInMonth : 0.0;
        $settings    = HrDeductionSetting::where('is_active', true)->get()->keyBy('code');

        $deductionSpec = [
            'absence'      => $att['absent'],
            'late'         => $att['late'],
            'half_day'     => $att['half_day'],
            'unpaid_leave' => $unpaidDays,
        ];

        $deductionLines  = [];
        $totalDeductions = 0.0;
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

        $bonuses      = $this->bonusPenalty->bonusesFor($employee->id, $from, $to);
        $penalties    = $this->bonusPenalty->penaltiesFor($employee->id, $from, $to);
        $bonusTotal   = round((float) $bonuses->sum('amount'), 2);
        $penaltyTotal = round((float) $penalties->sum('amount'), 2);
        $totalDeductions += $penaltyTotal;

        $gross = round($base + $personal['amount'] + $branch['total'] + $bonusTotal, 2);

        return [
            'base'            => $base,
            'personal'        => $personal,
            'branch'          => $branch,
            'attendance'      => $att,
            'unpaidDays'      => $unpaidDays,
            'daysInMonth'     => $daysInMonth,
            'dailyRate'       => $dailyRate,
            'deductionLines'  => $deductionLines,
            'totalDeductions' => $totalDeductions,
            'bonuses'         => $bonuses,
            'penalties'       => $penalties,
            'bonusTotal'      => $bonusTotal,
            'penaltyTotal'    => $penaltyTotal,
            'gross'           => $gross,
        ];
    }

    /**
     * Final Settlement preview for an employee leaving mid-month — read-only,
     * persists nothing (no Payroll row). Reuses computeComponents() for every
     * component EXCEPT the salary itself, which is prorated to the Leaving
     * Date instead of a full month:
     *
     *   Salary Earned = (Base Salary / Days in Month) × Worked Days
     *
     * Everything else (commission, branch bonus, bonuses, attendance
     * deductions, penalties) runs over the same [start of leaving month, end
     * of leaving month] window generate() would use for that month — only the
     * salary portion changes. Outstanding salary-advance balance is settled
     * in full (not just the month's scheduled installment), since the
     * employee is leaving.
     */
    public function settlementPreview(User $employee, Carbon $leavingDate): array
    {
        $from = $leavingDate->copy()->startOfMonth();
        $to   = $from->copy()->endOfMonth();

        $fullBase    = (float) $employee->base_salary;
        $daysInMonth = $from->daysInMonth;
        $workedDays  = min($leavingDate->day, $daysInMonth);
        $dailyRate   = $daysInMonth > 0 ? round($fullBase / $daysInMonth, 4) : 0.0;
        $salaryEarned = round($dailyRate * $workedDays, 2);

        $c = $this->computeComponents($employee, $from, $to, $salaryEarned);

        $advanceBalance = round((float) SalaryAdvance::where('user_id', $employee->id)
            ->where('status', SalaryAdvance::ACTIVE)
            ->get()->sum('remaining_balance'), 2);

        $net             = round($c['gross'] - $c['totalDeductions'], 2);
        $finalSettlement = round($net - $advanceBalance, 2);

        return [
            'basic_salary'        => $fullBase,
            'days_in_month'       => $daysInMonth,
            'worked_days'         => $workedDays,
            'daily_rate'          => round($dailyRate, 2),
            'salary_earned'       => $salaryEarned,
            'personal_commission' => $c['personal']['amount'],
            'branch_bonus'        => $c['branch']['total'],
            'bonus_total'         => $c['bonusTotal'],
            'penalty_total'       => $c['penaltyTotal'],
            'deduction_lines'     => $c['deductionLines'],
            'attendance_deductions' => round($c['totalDeductions'] - $c['penaltyTotal'], 2),
            'total_deductions'    => round($c['totalDeductions'], 2),
            'advance_balance'     => $advanceBalance,
            'gross'               => $c['gross'],
            'net'                 => $net,
            'final_settlement'    => $finalSettlement,
        ];
    }
}
