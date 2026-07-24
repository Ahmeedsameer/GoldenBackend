<?php

namespace App\Modules\Hr\Services;

use App\Models\Currency;
use App\Models\Payroll;
use App\Models\Safe;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\SalaryAdvanceRepayment;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use App\Modules\Safe\Services\SafeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee Salary Advance requests + admin-defined installment plan.
 *
 * Employees never receive advances automatically — every advance starts as a
 * PENDING request. Approving one requires the Admin to define an installment
 * plan in the same action — the plan (salary_advance_installments) is the
 * SINGLE SOURCE OF TRUTH the payroll engine follows every month; payroll never
 * calculates a deduction, it only looks up whether a row exists for its exact
 * (user, year, month). `paid_amount` is the one running total (installment
 * deductions AND early repayments both add to it); `remaining_balance` is
 * always derived (`approved_amount - paid_amount`), never stored, so it can
 * never drift out of sync.
 */
class SalaryAdvanceService
{
    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
        private SafeService $safes,
    ) {}

    /** Company-wide default currency for advance disbursements/repayments — same fallback SalesService already uses. */
    private function defaultCurrencyId(): int
    {
        return Currency::where('code', 'EGP')->value('id');
    }

    public function create(User $employee, array $data): SalaryAdvance
    {
        $advance = SalaryAdvance::create([
            'user_id'          => $employee->id,
            'requested_amount' => $data['requested_amount'],
            'reason'           => $data['reason'],
            'notes'            => $data['notes'] ?? null,
            'request_date'     => $data['request_date'] ?? now()->toDateString(),
            'status'           => SalaryAdvance::PENDING,
        ]);

        $this->audit->log('advance.requested', $advance, null, $advance->only(['user_id', 'requested_amount', 'reason']));
        $this->notifyReviewers($employee, 'طلب سلفة جديد',
            "{$employee->name}: " . number_format((float) $advance->requested_amount, 2) . " ج.م — {$advance->reason}");

        return $advance;
    }

    public function reject(SalaryAdvance $advance, ?string $reason, User $actor): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::PENDING) {
            throw ValidationException::withMessages(['advance' => 'يمكن رفض الطلبات قيد المراجعة فقط.']);
        }

        $advance->update([
            'status' => SalaryAdvance::REJECTED, 'rejection_reason' => $reason,
            'reviewed_by' => $actor->id, 'reviewed_at' => now(),
        ]);

        $this->audit->log('advance.rejected', $advance, ['status' => SalaryAdvance::PENDING], ['status' => SalaryAdvance::REJECTED]);
        $this->notifications->notify([$advance->user_id], 'advance', 'تم رفض طلب السلفة',
            'تم رفض طلب السلفة الخاص بك.' . ($reason ? " السبب: {$reason}" : ''),
            ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);

        return $advance->fresh('user');
    }

    /**
     * Approve + define the installment plan in one action. `mode` is one of:
     *   date_range   — Approved Amount + Start Month + End Month, split evenly (the
     *                  primary/recommended workflow).
     *   fixed_amount — a fixed monthly amount; the number of months is computed.
     *   fixed_months — a fixed number of months; the monthly amount is computed.
     *   custom       — the admin enters every month's amount explicitly.
     *
     * @param  array{approved_amount:float, mode:string, safe_id:int, monthly_amount?:float, months?:int, schedule?:array, start_year?:int, start_month?:int, end_year?:int, end_month?:int}  $plan
     */
    public function approve(SalaryAdvance $advance, array $plan, User $actor): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::PENDING) {
            throw ValidationException::withMessages(['advance' => 'يمكن اعتماد الطلبات قيد المراجعة فقط.']);
        }

        $approvedAmount = round((float) $plan['approved_amount'], 2);
        if ($approvedAmount <= 0 || $approvedAmount > (float) $advance->requested_amount) {
            throw ValidationException::withMessages(['approved_amount' => 'المبلغ المعتمد يجب أن يكون أكبر من صفر ولا يتجاوز المبلغ المطلوب.']);
        }

        // Part 6.1 — the admin picks WHICH Safe/Custody pays this advance out (Main
        // Safe or any branch's) — never an implicit "employee's branch custody".
        $safe = Safe::where('is_active', true)->findOrFail((int) $plan['safe_id']);

        $start = (! empty($plan['start_year']) && ! empty($plan['start_month']))
            ? Carbon::create((int) $plan['start_year'], (int) $plan['start_month'], 1)
            : Carbon::now()->addMonthNoOverflow()->startOfMonth();

        $rows = $this->buildInstallmentRows($approvedAmount, $plan['mode'], $plan, $start, 1);

        return DB::transaction(function () use ($advance, $approvedAmount, $plan, $rows, $actor, $safe) {
            $advance->update([
                'status' => SalaryAdvance::ACTIVE,
                'approved_amount' => $approvedAmount,
                'installment_mode' => $plan['mode'],
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'paying_safe_id' => $safe->id,
            ]);

            foreach ($rows as $row) {
                $advance->installments()->create($row);
            }

            $this->safes->recordAdvanceDisbursement(
                $safe, $advance, $this->defaultCurrencyId(), $approvedAmount, $actor->id,
                "صرف سلفة للموظف {$advance->user->name}",
            );

            $this->audit->log('advance.approved', $advance, ['status' => SalaryAdvance::PENDING], ['status' => SalaryAdvance::ACTIVE, 'approved_amount' => $approvedAmount, 'paying_safe_id' => $safe->id]);
            $this->audit->log('advance.plan_created', $advance, null, ['mode' => $plan['mode'], 'installments' => count($rows)]);

            $this->notifications->notify([$advance->user_id], 'advance', 'تمت الموافقة على طلب السلفة',
                'تمت الموافقة على سلفتك بمبلغ ' . number_format($approvedAmount, 2) . ' ج.م على ' . count($rows) . ' قسط.',
                ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);
            $this->notifyAdmins('تمت الموافقة على سلفة',
                "تمت الموافقة على سلفة {$advance->user->name} بمبلغ " . number_format($approvedAmount, 2) . ' ج.م.', $advance->id);

            return $advance->fresh(['installments', 'user']);
        });
    }

    /**
     * Cancel an APPROVED (active) advance — only while nothing has been
     * deducted or repaid yet ("before deductions start").
     */
    public function cancel(SalaryAdvance $advance, User $actor): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::ACTIVE) {
            throw ValidationException::withMessages(['advance' => 'يمكن إلغاء السلف النشطة فقط.']);
        }
        if ((float) $advance->paid_amount > 0) {
            throw ValidationException::withMessages(['advance' => 'لا يمكن إلغاء سلفة بدأ خصم أقساطها بالفعل.']);
        }

        return DB::transaction(function () use ($advance, $actor) {
            $advance->installments()->where('status', SalaryAdvanceInstallment::PENDING)->update(['status' => SalaryAdvanceInstallment::CANCELLED]);
            $advance->update(['status' => SalaryAdvance::CANCELLED]);

            // The disbursement already left the paying Safe at approval time — cancelling
            // before any deduction/repayment means it was never actually handed to the
            // employee, so credit it straight back to the same Safe it came from.
            if ($advance->paying_safe_id) {
                $this->safes->recordAdvanceRepayment(
                    Safe::findOrFail($advance->paying_safe_id), $advance, $this->defaultCurrencyId(),
                    (float) $advance->approved_amount, $actor->id,
                    "إلغاء سلفة الموظف {$advance->user->name} — إعادة المبلغ للخزنة",
                );
            }

            $this->audit->log('advance.cancelled', $advance, ['status' => SalaryAdvance::ACTIVE], ['status' => SalaryAdvance::CANCELLED, 'cancelled_by' => $actor->id]);

            $this->notifications->notify([$advance->user_id], 'advance', 'تم إلغاء السلفة',
                'تم إلغاء سلفتك المعتمدة قبل بدء خصم أي أقساط.', ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);

            return $advance->fresh('installments');
        });
    }

    /**
     * Edit the installment plan of an ACTIVE advance. Only still-PENDING
     * installments are replaced — anything already paid is untouched
     * (immutable, exactly like a locked payroll line).
     */
    public function updatePlan(SalaryAdvance $advance, array $plan): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::ACTIVE) {
            throw ValidationException::withMessages(['advance' => 'يمكن تعديل خطة السلف النشطة فقط.']);
        }

        $remaining = $advance->remaining_balance;
        $lastPaid = $advance->installments()->where('status', SalaryAdvanceInstallment::PAID)->orderByDesc('sequence')->first();
        $nextSequence = $lastPaid ? $lastPaid->sequence + 1 : 1;

        $start = $lastPaid
            ? Carbon::create($lastPaid->period_year, $lastPaid->period_month, 1)->addMonthNoOverflow()
            : ((! empty($plan['start_year']) && ! empty($plan['start_month']))
                ? Carbon::create((int) $plan['start_year'], (int) $plan['start_month'], 1)
                : Carbon::now()->addMonthNoOverflow()->startOfMonth());

        $rows = $this->buildInstallmentRows($remaining, $plan['mode'], $plan, $start, $nextSequence);

        return DB::transaction(function () use ($advance, $rows, $plan) {
            $before = $advance->installments()->where('status', SalaryAdvanceInstallment::PENDING)
                ->get(['period_year', 'period_month', 'planned_amount'])->toArray();

            $advance->installments()->where('status', SalaryAdvanceInstallment::PENDING)->delete();
            foreach ($rows as $row) {
                $advance->installments()->create($row);
            }
            $advance->update(['installment_mode' => $plan['mode']]);

            $this->audit->log('advance.plan_updated', $advance,
                ['pending_installments' => $before],
                ['pending_installments' => $rows]);

            return $advance->fresh('installments');
        });
    }

    /** Admin records a manual/early payment outside the normal payroll cycle. */
    public function recordEarlyRepayment(SalaryAdvance $advance, float $amount, string $date, int $safeId, User $actor, ?string $notes = null): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::ACTIVE) {
            throw ValidationException::withMessages(['advance' => 'يمكن تسجيل سداد مبكر للسلف النشطة فقط.']);
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $advance->remaining_balance + 0.01) {
            throw ValidationException::withMessages(['amount' => 'المبلغ غير صالح أو يتجاوز الرصيد المتبقي.']);
        }

        // Part 6.2 — the admin picks WHICH Safe/Custody actually received this repayment.
        $safe = Safe::where('is_active', true)->findOrFail($safeId);

        return DB::transaction(function () use ($advance, $amount, $date, $actor, $notes, $safe) {
            SalaryAdvanceRepayment::create([
                'salary_advance_id' => $advance->id, 'safe_id' => $safe->id, 'amount' => $amount, 'date' => $date,
                'recorded_by' => $actor->id, 'notes' => $notes,
            ]);

            $this->safes->recordAdvanceRepayment(
                $safe, $advance, $this->defaultCurrencyId(), $amount, $actor->id,
                "سداد مبكر لسلفة الموظف {$advance->user->name}",
            );

            $before = ['paid_amount' => (float) $advance->paid_amount];
            $advance->paid_amount = round((float) $advance->paid_amount + $amount, 2);
            $advance->save();

            $this->audit->log('advance.early_repayment', $advance, $before,
                ['paid_amount' => (float) $advance->paid_amount, 'repayment' => $amount]);

            if ($advance->remaining_balance <= 0.01) {
                $this->markCompleted($advance);
            } else {
                $this->redistributePendingInstallments($advance, $advance->remaining_balance);
            }

            $this->notifications->notify([$advance->user_id], 'advance', 'تم تسجيل سداد مبكر لسلفتك',
                'تم سداد ' . number_format($amount, 2) . ' ج.م مبكرًا — الرصيد المتبقي الآن ' . number_format($advance->fresh()->remaining_balance, 2) . ' ج.م.',
                ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);

            return $advance->fresh(['installments', 'repayments']);
        });
    }

    /**
     * Called by PayrollService BEFORE the Payroll row is created, so the
     * deduction amount can be folded into that month's net-salary formula.
     * The deduction period always matches the installment schedule exactly —
     * payroll never computes an amount itself, only looks up this row.
     *
     * @return array{advance: SalaryAdvance, installment: SalaryAdvanceInstallment}|null
     */
    public function deductForPayroll(User $employee, int $year, int $month): ?array
    {
        $advance = SalaryAdvance::where('user_id', $employee->id)->where('status', SalaryAdvance::ACTIVE)->first();
        if (! $advance) {
            return null;
        }

        $installment = $advance->installments()
            ->where('period_year', $year)->where('period_month', $month)
            ->where('status', SalaryAdvanceInstallment::PENDING)->first();

        return $installment ? ['advance' => $advance, 'installment' => $installment] : null;
    }

    /** Called by PayrollService AFTER the Payroll row exists, to link + finalize the deduction. */
    public function markInstallmentDeducted(SalaryAdvanceInstallment $installment, Payroll $payroll): void
    {
        $advance = $installment->advance;
        $installment->update(['status' => SalaryAdvanceInstallment::PAID, 'payroll_id' => $payroll->id, 'deducted_at' => now()]);

        $before = ['paid_amount' => (float) $advance->paid_amount];
        $advance->paid_amount = round((float) $advance->paid_amount + (float) $installment->planned_amount, 2);
        $advance->save();

        $this->audit->log('advance.installment_paid', $advance, $before,
            ['paid_amount' => (float) $advance->paid_amount, 'installment_id' => $installment->id, 'payroll_id' => $payroll->id]);

        $monthLabel = "{$installment->period_month}/{$installment->period_year}";
        $employee = $advance->user;

        $this->notifications->notify([$advance->user_id], 'advance', "تم خصم قسط السلفة ({$monthLabel})",
            'تم خصم ' . number_format((float) $installment->planned_amount, 2) . ' ج.م من راتبك كقسط سلفة — الرصيد المتبقي '
                . number_format($advance->fresh()->remaining_balance, 2) . ' ج.م.',
            ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);
        $this->notifyAdmins("تمت معالجة قسط سلفة ({$monthLabel})",
            "تم خصم قسط سلفة {$monthLabel} لـ {$employee->name} بنجاح.", $advance->id);

        if ($advance->fresh()->remaining_balance <= 0.01) {
            $this->markCompleted($advance->fresh());
        }
    }

    /**
     * Scan for installments whose period is the CURRENT calendar month and
     * still pending — notify employee + admins once each (guarded by
     * `reminded_at` so a re-run never double-notifies). Meant to run once at
     * the start of every month via a scheduled command.
     *
     * @return int number of installments reminded
     */
    public function notifyDueInstallments(): int
    {
        $now = Carbon::now();
        $due = SalaryAdvanceInstallment::with('advance.user')
            ->where('period_year', $now->year)->where('period_month', $now->month)
            ->where('status', SalaryAdvanceInstallment::PENDING)
            ->whereNull('reminded_at')
            ->get();

        foreach ($due as $installment) {
            $advance = $installment->advance;
            $employee = $advance->user;
            $monthLabel = "{$installment->period_month}/{$installment->period_year}";

            $installment->update(['reminded_at' => now()]);

            $this->notifications->notify([$employee->id], 'advance', "قسط سلفة مستحق ({$monthLabel})",
                'قسط سلفتك لهذا الشهر (' . number_format((float) $installment->planned_amount, 2) . ' ج.م) سيُخصم من راتب هذا الشهر.',
                ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);
            $this->notifyAdmins("قسط سلفة مستحق ({$monthLabel})",
                "قسط سلفة {$monthLabel} مستحق للموظف {$employee->name}.", $advance->id);

            $this->audit->log('advance.installment_due_reminder', $advance, null,
                ['installment_id' => $installment->id, 'period' => $monthLabel]);
        }

        return $due->count();
    }

    private function markCompleted(SalaryAdvance $advance): void
    {
        $advance->installments()->where('status', SalaryAdvanceInstallment::PENDING)->update(['status' => SalaryAdvanceInstallment::SKIPPED]);
        $advance->update(['status' => SalaryAdvance::COMPLETED, 'completed_at' => now()]);

        $this->audit->log('advance.completed', $advance, null, ['status' => SalaryAdvance::COMPLETED]);
        $this->notifications->notify([$advance->user_id], 'advance', 'تم سداد السلفة بالكامل',
            'تهانينا! تم سداد سلفتك بالكامل.', ['type' => 'advance', 'advance_id' => $advance->id, 'route' => '/dashboard/my-advances']);
        $this->notifyAdmins('اكتملت سلفة موظف', "اكتمل سداد سلفة {$advance->user->name} بالكامل.", $advance->id);
    }

    /** Evenly redistribute a new (lower) remaining balance across still-pending installments. */
    private function redistributePendingInstallments(SalaryAdvance $advance, float $newRemaining): void
    {
        $pending = $advance->installments()->where('status', SalaryAdvanceInstallment::PENDING)->orderBy('sequence')->get();
        if ($pending->isEmpty()) {
            return;
        }

        $count = $pending->count();
        $base = floor(($newRemaining / $count) * 100) / 100;
        $allocated = 0.0;
        foreach ($pending as $i => $installment) {
            $amount = ($i === $count - 1) ? round($newRemaining - $allocated, 2) : $base;
            $installment->update(['planned_amount' => $amount]);
            $allocated += $amount;
        }
    }

    /** @return array<int, array{period_year:int, period_month:int, sequence:int, planned_amount:float, status:string}> */
    private function buildInstallmentRows(float $amount, string $mode, array $plan, Carbon $start, int $firstSequence): array
    {
        $amounts = match ($mode) {
            SalaryAdvance::MODE_DATE_RANGE   => $this->splitByDateRange($amount, $start, $plan),
            SalaryAdvance::MODE_FIXED_AMOUNT => $this->splitByMonthlyAmount($amount, (float) ($plan['monthly_amount'] ?? 0)),
            SalaryAdvance::MODE_FIXED_MONTHS => $this->splitByMonths($amount, (int) ($plan['months'] ?? 0)),
            SalaryAdvance::MODE_CUSTOM       => $this->validateCustomSchedule($amount, $plan['schedule'] ?? []),
            default => throw ValidationException::withMessages(['mode' => 'خطة تقسيط غير صالحة.']),
        };

        $rows = [];
        $cursor = $start->copy();
        foreach ($amounts as $i => $installmentAmount) {
            $rows[] = [
                'period_year' => $cursor->year, 'period_month' => $cursor->month,
                'sequence' => $firstSequence + $i, 'planned_amount' => round($installmentAmount, 2),
                'status' => SalaryAdvanceInstallment::PENDING,
            ];
            $cursor->addMonthNoOverflow();
        }

        return $rows;
    }

    /** Approved Amount + Start Month + End Month → split evenly across the inclusive range. */
    private function splitByDateRange(float $amount, Carbon $start, array $plan): array
    {
        if (empty($plan['end_year']) || empty($plan['end_month'])) {
            throw ValidationException::withMessages(['end_month' => 'حدد شهر نهاية التقسيط.']);
        }
        $end = Carbon::create((int) $plan['end_year'], (int) $plan['end_month'], 1);
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_month' => 'شهر النهاية يجب أن يكون بعد أو يساوي شهر البداية.']);
        }
        $months = $start->diffInMonths($end) + 1;

        return $this->splitByMonths($amount, $months);
    }

    private function splitByMonthlyAmount(float $amount, float $monthly): array
    {
        if ($monthly <= 0 || $monthly > $amount) {
            throw ValidationException::withMessages(['monthly_amount' => 'قيمة القسط الشهري غير صالحة.']);
        }
        $count = (int) ceil($amount / $monthly);
        $amounts = array_fill(0, $count - 1, $monthly);
        $amounts[] = round($amount - $monthly * ($count - 1), 2);

        return $amounts;
    }

    private function splitByMonths(float $amount, int $months): array
    {
        if ($months <= 0) {
            throw ValidationException::withMessages(['months' => 'عدد الأشهر يجب أن يكون أكبر من صفر.']);
        }
        $base = floor(($amount / $months) * 100) / 100;
        $amounts = array_fill(0, $months - 1, $base);
        $amounts[] = round($amount - $base * ($months - 1), 2);

        return $amounts;
    }

    private function validateCustomSchedule(float $amount, array $schedule): array
    {
        $amounts = array_map(fn ($v) => round((float) $v, 2), $schedule);
        $sum = round(array_sum($amounts), 2);
        if (empty($amounts) || abs($sum - $amount) > 0.02) {
            throw ValidationException::withMessages(['schedule' => "مجموع الخطة المخصصة ({$sum}) لا يساوي المبلغ المعتمد ({$amount})."]);
        }

        return $amounts;
    }

    /** Notify admins + the employee's branch manager — used for the initial request only (manager should know a branch employee asked). */
    private function notifyReviewers(User $employee, string $title, string $message): void
    {
        $data = ['type' => 'advance', 'route' => '/dashboard/hr/advances'];
        $managerId = $employee->shop_id ? Shop::where('id', $employee->shop_id)->value('manager_id') : null;
        if ($managerId) {
            $this->notifications->notify([$managerId], 'advance', $title, $message, $data);
        }
        $this->notifications->notifyAdmins('advance', $title, $message, $data);
    }

    /** Admins only — used for approve/deduction/due-reminder/completion, matching spec's "Employee + Admin" wording exactly. */
    private function notifyAdmins(string $title, string $message, int $advanceId): void
    {
        $this->notifications->notifyAdmins('advance', $title, $message,
            ['type' => 'advance', 'advance_id' => $advanceId, 'route' => '/dashboard/hr/advances']);
    }
}
