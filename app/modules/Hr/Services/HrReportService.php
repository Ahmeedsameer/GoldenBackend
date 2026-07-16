<?php

namespace App\Modules\Hr\Services;

use App\Models\Attendance;
use App\Models\Bonus;
use App\Models\EmployeeTransfer;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Unified HR reporting. Every report returns the same shape:
 *   ['title' => string, 'columns' => string[], 'rows' => array<array>]
 * so a single export path (CSV / PDF) serves them all.
 */
class HrReportService
{
    public function __construct(
        private CommissionService $commissions,
        private AttendanceService $attendance,
        private ScheduleService $schedule,
    ) {}

    public function build(string $type, array $f): array
    {
        return match ($type) {
            'employee_sales'         => $this->employeeSales($f),
            'branch_sales'           => $this->branchSales($f),
            'attendance'             => $this->attendanceReport($f),
            'leaves'                 => $this->leaves($f),
            'payroll'                => $this->payroll($f),
            'commissions'            => $this->commissions($f),
            'top_performers'         => $this->topPerformers($f),
            'branch_performance'     => $this->branchPerformance($f),
            'monthly_comparison'     => $this->monthlyComparison($f),
            'transfers'              => $this->transfers($f),
            'transfer_earnings'      => $this->transferEarnings($f),
            'schedule_weekly'        => $this->scheduleWeekly($f),
            'schedule_employee'      => $this->scheduleEmployee($f),
            'schedule_branch'        => $this->scheduleBranch($f),
            'schedule_transferred'   => $this->scheduleTransferred($f),
            'attendance_vs_schedule' => $this->attendanceVsSchedule($f),
            'leave_vs_schedule'      => $this->leaveVsSchedule($f),
            'schedule_conflicts'     => $this->scheduleConflicts($f),
            'late_employees'         => $this->lateEmployees($f),
            'bonuses'                => $this->bonuses($f),
            'penalties'              => $this->penalties($f),
            'late_deductions'        => $this->deductionsByCode('late', $f),
            'absence_deductions'     => $this->deductionsByCode('absence', $f),
            'leave_deductions'       => $this->deductionsByCode('unpaid_leave', $f),
            'payroll_breakdown'      => $this->payrollBreakdown($f),
            'leave_usage'            => $this->leaveUsage($f),
            'advances_pending'       => $this->advancesByStatus(SalaryAdvance::PENDING, 'طلبات السلف قيد المراجعة', $f),
            'advances_active'        => $this->advancesByStatus(SalaryAdvance::ACTIVE, 'السلف النشطة (خطط تقسيط قائمة)', $f, true),
            'advances_rejected'      => $this->advancesByStatus(SalaryAdvance::REJECTED, 'طلبات السلف المرفوضة', $f),
            'advances_completed'     => $this->advancesByStatus(SalaryAdvance::COMPLETED, 'السلف المسددة بالكامل', $f),
            'advances_deductions'    => $this->advanceDeductions($f),
            'advances_due'           => $this->advancesDueThisMonth($f),
            'advances_by_employee'   => $this->advancesByEmployee($f),
            'advances_by_branch'     => $this->advancesByBranch($f),
            default                  => ['title' => 'تقرير', 'columns' => [], 'rows' => []],
        };
    }

    private function period(array $f): array
    {
        $from = ! empty($f['from']) ? Carbon::parse($f['from']) : Carbon::now()->startOfMonth();
        $to   = ! empty($f['to'])   ? Carbon::parse($f['to'])   : Carbon::now()->endOfMonth();
        return [$from, $to];
    }

    private function approvedInvoices(Carbon $from, Carbon $to)
    {
        return Invoice::where('status', 'approved')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    // ── Sales ─────────────────────────────────────────────────────────────────
    private function employeeSales(array $f): array
    {
        [$from, $to] = $this->period($f);
        $rows = (clone $this->approvedInvoices($from, $to))
            ->selectRaw('seller_id, seller_name, COUNT(*) as invoices, SUM(total_amount) as total')
            ->groupBy('seller_id', 'seller_name')
            ->orderByDesc('total')->get();

        return [
            'title'   => "مبيعات الموظفين {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'عدد الفواتير', 'إجمالي المبيعات'],
            'rows'    => $rows->map(fn ($r) => [$r->seller_name ?? "#{$r->seller_id}", $r->invoices, number_format((float) $r->total, 2)])->all(),
        ];
    }

    private function branchSales(array $f): array
    {
        [$from, $to] = $this->period($f);
        $rows = (clone $this->approvedInvoices($from, $to))
            ->selectRaw('shop_id, branch_name, COUNT(*) as invoices, SUM(total_amount) as total')
            ->groupBy('shop_id', 'branch_name')
            ->orderByDesc('total')->get();

        return [
            'title'   => "مبيعات الفروع {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الفرع', 'عدد الفواتير', 'إجمالي المبيعات'],
            'rows'    => $rows->map(fn ($r) => [$r->branch_name ?? "#{$r->shop_id}", $r->invoices, number_format((float) $r->total, 2)])->all(),
        ];
    }

    // ── Attendance ────────────────────────────────────────────────────────────
    private function attendanceReport(array $f): array
    {
        [$from, $to] = $this->period($f);
        $employees = User::whereIn('role', ['manager', 'sales'])
            ->when(! empty($f['shop_id']), fn ($q) => $q->where('shop_id', (int) $f['shop_id']))
            ->orderBy('name')->get(['id', 'name']);

        $rows = $employees->map(function ($e) use ($from, $to) {
            $s = $this->attendance->summary($e->id, $from, $to);
            return [$e->name, $s['present'], $s['late'], $s['half_day'], $s['absent']];
        })->all();

        return [
            'title'   => "الحضور {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'حاضر', 'متأخر', 'نصف يوم', 'غائب'],
            'rows'    => $rows,
        ];
    }

    // ── Leaves ────────────────────────────────────────────────────────────────
    private function leaves(array $f): array
    {
        $q = LeaveRequest::with('user:id,name')
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['year']), fn ($q) => $q->whereYear('start_date', (int) $f['year']))
            ->latest();

        return [
            'title'   => 'تقرير الإجازات',
            'columns' => ['الموظف', 'من', 'إلى', 'الأيام', 'النوع', 'الحالة', 'مدفوع', 'بدون أجر'],
            'rows'    => $q->get()->map(fn ($l) => [
                $l->user?->name, $l->start_date->toDateString(), $l->end_date->toDateString(),
                $l->days, $l->type, $l->status, $l->paid_days, $l->unpaid_days,
            ])->all(),
        ];
    }

    // ── Payroll ───────────────────────────────────────────────────────────────
    private function payroll(array $f): array
    {
        $q = Payroll::with('user:id,name')
            ->when(! empty($f['year']), fn ($q) => $q->where('period_year', (int) $f['year']))
            ->when(! empty($f['month']), fn ($q) => $q->where('period_month', (int) $f['month']))
            ->latest();

        return [
            'title'   => 'تقرير الرواتب',
            'columns' => ['الموظف', 'الشهر', 'أساسي', 'عمولة شخصية', 'بونص فرع', 'خصومات', 'الصافي', 'الحالة'],
            'rows'    => $q->get()->map(fn ($p) => [
                $p->user?->name, "{$p->period_month}/{$p->period_year}",
                number_format((float) $p->base_salary, 2), number_format((float) $p->personal_commission_amount, 2),
                number_format((float) $p->branch_commission_amount, 2), number_format((float) $p->total_deductions, 2),
                number_format((float) $p->net_salary, 2), $p->is_locked ? 'مقفول' : $p->status,
            ])->all(),
        ];
    }

    // ── Commissions ───────────────────────────────────────────────────────────
    private function commissions(array $f): array
    {
        [$from, $to] = $this->period($f);
        $employees = User::whereIn('role', ['manager', 'sales'])->where('status', 'active')->orderBy('name')->get();

        $rows = $employees->map(function ($e) use ($from, $to) {
            $p = $this->commissions->personalCommission($e, $from, $to);
            $b = $this->commissions->branchCommission($e, $from, $to);
            return [$e->name, number_format($p['sales_total'], 2), number_format($p['amount'], 2), number_format($b['total'], 2),
                number_format((float) $e->base_salary + $p['amount'] + $b['total'], 2)];
        })->all();

        return [
            'title'   => "العمولات {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'مبيعاته', 'عمولة شخصية', 'بونص فرع', 'راتب تقديري'],
            'rows'    => $rows,
        ];
    }

    private function topPerformers(array $f): array
    {
        $r = $this->employeeSales($f);
        $r['title'] = 'أفضل الموظفين أداءً';
        $r['rows']  = array_slice($r['rows'], 0, (int) ($f['limit'] ?? 10));
        return $r;
    }

    private function branchPerformance(array $f): array
    {
        [$from, $to] = $this->period($f);
        $rows = Shop::get(['id', 'name', 'branch_bonus_percent'])->map(function ($s) use ($from, $to) {
            $total = (float) (clone $this->approvedInvoices($from, $to))->where('shop_id', $s->id)->sum('total_amount');
            $heads = User::whereIn('role', ['manager', 'sales'])->where('status', 'active')->where('shop_id', $s->id)->count();
            return [$s->name, number_format($total, 2), $s->branch_bonus_percent . '%', $heads, $heads ? number_format($total / $heads, 2) : '0'];
        })->all();

        return [
            'title'   => "أداء الفروع {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الفرع', 'المبيعات', 'نسبة البونص', 'عدد الموظفين', 'متوسط لكل موظف'],
            'rows'    => $rows,
        ];
    }

    private function monthlyComparison(array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $from = Carbon::create($year, $m, 1)->startOfMonth();
            $to   = $from->copy()->endOfMonth();
            $total = (float) $this->approvedInvoices($from, $to)->sum('total_amount');
            $rows[] = ["{$m}/{$year}", number_format($total, 2)];
        }
        return ['title' => "المقارنة الشهرية {$year}", 'columns' => ['الشهر', 'إجمالي المبيعات'], 'rows' => $rows];
    }

    // ── Transfers ───────────────────────────────────────────────────────────────
    private function transfers(array $f): array
    {
        $q = EmployeeTransfer::with(['user:id,name', 'primaryBranch:id,name', 'temporaryBranch:id,name'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->when(! empty($f['shop_id']), fn ($q) => $q->where(fn ($w) =>
                $w->where('temporary_branch_id', (int) $f['shop_id'])->orWhere('primary_branch_id', (int) $f['shop_id'])))
            ->latest();

        $statusLabel = ['draft' => 'مسودّة', 'scheduled' => 'مجدول', 'active' => 'نشط', 'completed' => 'مكتمل', 'cancelled' => 'ملغي'];

        return [
            'title'   => 'تقرير نقل الموظفين' . (! empty($f['status']) ? " — {$statusLabel[$f['status']]}" : ''),
            'columns' => ['الموظف', 'من فرع', 'إلى فرع', 'من', 'إلى', 'المدة (يوم)', 'الحالة'],
            'rows'    => $q->get()->map(fn ($t) => [
                $t->user?->name, $t->primaryBranch?->name, $t->temporaryBranch?->name,
                $t->start_date->toDateString(), $t->end_date->toDateString(),
                $t->start_date->diffInDays($t->end_date) + 1, $statusLabel[$t->status] ?? $t->status,
            ])->all(),
        ];
    }

    /** Sales + branch bonus each employee earned DURING each transfer period. */
    private function transferEarnings(array $f): array
    {
        $transfers = EmployeeTransfer::with(['user', 'temporaryBranch:id,name'])
            ->whereIn('status', ['active', 'completed'])
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->latest()->get();

        $rows = $transfers->map(function ($t) {
            $from = Carbon::parse($t->start_date);
            $to   = Carbon::parse($t->end_date);
            $sales = (float) $this->approvedInvoices($from, $to)->where('seller_id', $t->user_id)->sum('total_amount');
            $bonus = $t->user ? $this->commissions->branchCommission($t->user, $from, $to)['total'] : 0;
            return [
                $t->user?->name, $t->temporaryBranch?->name,
                "{$from->toDateString()} → {$to->toDateString()}",
                number_format($sales, 2), number_format((float) $bonus, 2),
            ];
        })->all();

        return [
            'title'   => 'المبيعات والبونص خلال فترات النقل',
            'columns' => ['الموظف', 'الفرع المؤقت', 'الفترة', 'مبيعاته خلال النقل', 'بونص الفرع خلال النقل'],
            'rows'    => $rows,
        ];
    }

    // ── Schedule ──────────────────────────────────────────────────────────────

    private $typeLabels = [
        'work' => 'عمل', 'leave' => 'إجازة', 'off_day' => 'إجازة أسبوعية',
        'holiday' => 'عطلة رسمية', 'sick_leave' => 'إجازة مرضية', 'training' => 'تدريب',
        'business_trip' => 'مهمة عمل', 'absent' => 'غياب', 'late' => 'تأخير', 'half_day' => 'نصف يوم',
        'transferred' => 'منقول',
    ];

    private function weekGridReport(array $roster, string $title): array
    {
        $columns = array_merge(['الموظف', 'الفرع الأساسي'], $roster['days']);
        $rows = array_map(function ($emp) {
            $cells = array_map(function ($c) {
                if (! $c['type']) return '—';
                $label = $this->typeLabels[$c['type']] ?? $c['type'];
                if ($c['type'] === 'work' && ! empty($c['start_time'])) {
                    $label .= " ({$c['start_time']}-{$c['end_time']})";
                }
                return $label;
            }, $emp['cells']);
            return array_merge([$emp['name'], $emp['primary_branch'] ?? '-'], $cells);
        }, $roster['employees']);

        return ['title' => $title . " {$roster['from']} → {$roster['to']}", 'columns' => $columns, 'rows' => $rows];
    }

    private function scheduleWeekly(array $f): array
    {
        [$from, $to] = $this->schedule->weekBounds($f['date'] ?? null);
        $roster = $this->schedule->weekRoster($from, $to, array_intersect_key($f, array_flip(['shop_id', 'role', 'status'])));
        return $this->weekGridReport($roster, 'الجدول الأسبوعي');
    }

    private function scheduleEmployee(array $f): array
    {
        [$from, $to] = $this->schedule->weekBounds($f['date'] ?? null);
        $roster = $this->schedule->weekRoster($from, $to, ['user_id' => $f['user_id'] ?? null]);
        return $this->weekGridReport($roster, 'جدول الموظف');
    }

    private function scheduleBranch(array $f): array
    {
        [$from, $to] = $this->schedule->weekBounds($f['date'] ?? null);
        $roster = $this->schedule->weekRoster($from, $to, ['shop_id' => $f['shop_id'] ?? null]);
        return $this->weekGridReport($roster, 'جدول الفرع');
    }

    private function scheduleTransferred(array $f): array
    {
        [$from, $to] = $this->schedule->weekBounds($f['date'] ?? null);
        $roster = $this->schedule->weekRoster($from, $to, array_intersect_key($f, array_flip(['shop_id'])));

        $rows = [];
        foreach ($roster['employees'] as $emp) {
            foreach ($emp['cells'] as $c) {
                if ($c['type'] === 'transferred') {
                    $rows[] = [$emp['name'], $emp['primary_branch'] ?? '-', $c['transfer']['branch_name'] ?? '-', $c['date']];
                }
            }
        }

        return [
            'title'   => "الموظفون المنقولون {$roster['from']} → {$roster['to']}",
            'columns' => ['الموظف', 'الفرع الأساسي', 'الفرع المؤقت', 'التاريخ'],
            'rows'    => $rows,
        ];
    }

    /** Days scheduled as "work" but attendance disagrees (absent/missing) or vice versa. */
    private function attendanceVsSchedule(array $f): array
    {
        [$from, $to] = $this->period($f);
        $entries = ScheduleEntry::with('user:id,name')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($f['shop_id']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('shop_id', (int) $f['shop_id'])))
            ->get();

        $attendance = Attendance::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->groupBy(fn ($a) => $a->user_id . '_' . $a->date->toDateString());

        $rows = [];
        foreach ($entries as $e) {
            $actual = $attendance->get($e->user_id . '_' . $e->date->toDateString())?->first();
            $scheduledWork = $e->type === 'work';
            $actualPresent = $actual && in_array($actual->status, ['present', 'late', 'half_day']);
            if ($scheduledWork !== $actualPresent) {
                $rows[] = [
                    $e->user?->name, $e->date->toDateString(),
                    $this->typeLabels[$e->type] ?? $e->type,
                    $actual ? ($actual->status) : 'لم يُسجَّل',
                ];
            }
        }

        return [
            'title'   => "الحضور مقابل الجدول {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'التاريخ', 'المجدول', 'الفعلي'],
            'rows'    => $rows,
        ];
    }

    /** Approved leave days that don't have a matching "leave" schedule entry. */
    private function leaveVsSchedule(array $f): array
    {
        [$from, $to] = $this->period($f);
        $leaves = LeaveRequest::with('user:id,name')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $rows = [];
        foreach ($leaves as $l) {
            $cursor = Carbon::parse(max($l->start_date, $from));
            $end    = Carbon::parse(min($l->end_date, $to));
            while ($cursor->lessThanOrEqualTo($end)) {
                $matches = ScheduleEntry::where('user_id', $l->user_id)
                    ->where('date', $cursor->toDateString())
                    ->where('type', 'leave')->exists();
                if (! $matches) {
                    $rows[] = [$l->user?->name, $cursor->toDateString(), 'إجازة معتمدة بدون خلية في الجدول'];
                }
                $cursor->addDay();
            }
        }

        return [
            'title'   => "الإجازات مقابل الجدول {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'التاريخ', 'الملاحظة'],
            'rows'    => $rows,
        ];
    }

    /** Persisted schedule entries that land inside an active/effective transfer period. */
    private function scheduleConflicts(array $f): array
    {
        [$from, $to] = $this->period($f);
        $entries = ScheduleEntry::with('user:id,name')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('type', ['work', 'training', 'business_trip'])
            ->get();

        $transfers = EmployeeTransfer::whereIn('status', EmployeeTransfer::EFFECTIVE_STATUSES)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get()->groupBy('user_id');

        $rows = [];
        foreach ($entries as $e) {
            $conflict = $transfers->get($e->user_id, collect())
                ->first(fn ($t) => $e->date->betweenIncluded($t->start_date, $t->end_date));
            if ($conflict) {
                $rows[] = [$e->user?->name, $e->date->toDateString(), $this->typeLabels[$e->type] ?? $e->type, 'يتعارض مع نقل مؤقت نشط'];
            }
        }

        return [
            'title'   => "تعارضات الجدول {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'التاريخ', 'النوع المجدول', 'التعارض'],
            'rows'    => $rows,
        ];
    }

    private function lateEmployees(array $f): array
    {
        [$from, $to] = $this->period($f);
        $rows = Attendance::where('status', 'late')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('user:id,name')
            ->when(! empty($f['shop_id']), fn ($q) => $q->where('shop_id', (int) $f['shop_id']))
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')
            ->orderByDesc('c')
            ->get();

        return [
            'title'   => "الموظفون المتأخرون {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'عدد مرات التأخير'],
            'rows'    => $rows->map(fn ($r) => [$r->user?->name, $r->c])->all(),
        ];
    }

    // ── Bonuses / Penalties / Deductions (Phase 10) ────────────────────────────

    private function bonuses(array $f): array
    {
        [$from, $to] = $this->period($f);
        $q = Bonus::with(['user:id,name', 'creator:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->orderByDesc('date');

        return [
            'title'   => "المكافآت {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'المبلغ', 'السبب', 'التاريخ', 'أضيفت بواسطة', 'الحالة'],
            'rows'    => $q->get()->map(fn ($b) => [
                $b->user?->name, number_format((float) $b->amount, 2), $b->reason,
                $b->date->toDateString(), $b->creator?->name, $b->status === 'active' ? 'فعّالة' : 'ملغاة',
            ])->all(),
        ];
    }

    private function penalties(array $f): array
    {
        [$from, $to] = $this->period($f);
        $q = Penalty::with(['user:id,name', 'creator:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->orderByDesc('date');

        return [
            'title'   => "الخصومات {$from->toDateString()} → {$to->toDateString()}",
            'columns' => ['الموظف', 'المبلغ', 'السبب', 'التاريخ', 'أضيفت بواسطة', 'الحالة'],
            'rows'    => $q->get()->map(fn ($p) => [
                $p->user?->name, number_format((float) $p->amount, 2), $p->reason,
                $p->date->toDateString(), $p->creator?->name, $p->status === 'active' ? 'فعّالة' : 'ملغاة',
            ])->all(),
        ];
    }

    /** Automatic attendance/leave deduction lines from generated payrolls, filtered by deduction code. */
    private function deductionsByCode(string $code, array $f): array
    {
        $labels = ['late' => 'خصومات التأخير', 'absence' => 'خصومات الغياب', 'unpaid_leave' => 'خصومات الإجازة بدون أجر'];

        $q = PayrollLine::with('payroll.user:id,name')
            ->where('type', PayrollLine::DEDUCTION)
            ->whereJsonContains('meta->code', $code)
            ->when(! empty($f['year']), fn ($q) => $q->whereHas('payroll', fn ($p) => $p->where('period_year', (int) $f['year'])))
            ->when(! empty($f['month']), fn ($q) => $q->whereHas('payroll', fn ($p) => $p->where('period_month', (int) $f['month'])));

        return [
            'title'   => $labels[$code] ?? 'خصومات',
            'columns' => ['الموظف', 'الشهر', 'عدد الوحدات', 'قيمة الوحدة', 'الإجمالي'],
            'rows'    => $q->get()->map(fn ($l) => [
                $l->payroll?->user?->name,
                $l->payroll ? "{$l->payroll->period_month}/{$l->payroll->period_year}" : '-',
                $l->meta['qty'] ?? '-', number_format((float) ($l->meta['per_unit'] ?? 0), 2),
                number_format(abs((float) $l->amount), 2),
            ])->all(),
        ];
    }

    /** Full payroll breakdown (every line, every employee) for a month. */
    private function payrollBreakdown(array $f): array
    {
        $year  = (int) ($f['year'] ?? now()->year);
        $month = (int) ($f['month'] ?? now()->month);

        $lineLabels = [
            PayrollLine::BASE => 'أساسي', PayrollLine::PERSONAL_COMMISSION => 'عمولة شخصية',
            PayrollLine::BRANCH_COMMISSION => 'بونص فرع', PayrollLine::DEDUCTION => 'خصم',
            PayrollLine::BONUS => 'مكافأة', PayrollLine::PENALTY => 'عقوبة',
        ];

        $payrolls = Payroll::with(['user:id,name', 'lines'])
            ->where('period_year', $year)->where('period_month', $month)
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->get();

        $rows = [];
        foreach ($payrolls as $p) {
            foreach ($p->lines as $l) {
                $rows[] = [$p->user?->name, $lineLabels[$l->type] ?? $l->type, $l->label, number_format((float) $l->amount, 2)];
            }
            $rows[] = [$p->user?->name, 'صافي', 'صافي الراتب', number_format((float) $p->net_salary, 2)];
        }

        return [
            'title'   => "تفصيل الرواتب {$month}/{$year}",
            'columns' => ['الموظف', 'النوع', 'البيان', 'المبلغ'],
            'rows'    => $rows,
        ];
    }

    /** Leave days used vs. monthly allowance, per employee, for a month. */
    private function leaveUsage(array $f): array
    {
        $year  = (int) ($f['year'] ?? now()->year);
        $month = (int) ($f['month'] ?? now()->month);

        $employees = User::whereIn('role', ['manager', 'sales'])
            ->when(! empty($f['shop_id']), fn ($q) => $q->where('shop_id', (int) $f['shop_id']))
            ->orderBy('name')->get();

        $rows = $employees->map(function (User $e) use ($year, $month) {
            $used = (int) LeaveRequest::where('user_id', $e->id)->where('status', LeaveRequest::APPROVED)
                ->whereYear('start_date', $year)->whereMonth('start_date', $month)->sum('paid_days');
            $unpaid = (int) LeaveRequest::where('user_id', $e->id)->where('status', LeaveRequest::APPROVED)
                ->whereYear('start_date', $year)->whereMonth('start_date', $month)->sum('unpaid_days');
            $allowance = (int) $e->monthly_leave_allowance;

            return [$e->name, $allowance, $used, max(0, $allowance - $used), $unpaid];
        })->all();

        return [
            'title'   => "استخدام الإجازات {$month}/{$year}",
            'columns' => ['الموظف', 'الرصيد الشهري', 'المستخدم (مدفوع)', 'المتبقي', 'بدون أجر'],
            'rows'    => $rows,
        ];
    }

    // ── Salary Advances (Phase 12) ──────────────────────────────────────────────

    private function advancesByStatus(string $status, string $title, array $f, bool $withBalance = false): array
    {
        $q = SalaryAdvance::with('user:id,name')
            ->where('status', $status)
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->latest();

        if ($withBalance) {
            return [
                'title'   => $title,
                'columns' => ['الموظف', 'المبلغ المعتمد', 'المدفوع', 'المتبقي', 'خطة التقسيط', 'تاريخ الاعتماد'],
                'rows'    => $q->get()->map(fn ($a) => [
                    $a->user?->name, number_format((float) $a->approved_amount, 2), number_format((float) $a->paid_amount, 2),
                    number_format($a->remaining_balance, 2), $this->modeLabel($a->installment_mode),
                    $a->reviewed_at?->format('Y-m-d') ?? '-',
                ])->all(),
            ];
        }

        $columns = $status === SalaryAdvance::PENDING
            ? ['الموظف', 'المبلغ المطلوب', 'السبب', 'تاريخ الطلب']
            : ($status === SalaryAdvance::REJECTED
                ? ['الموظف', 'المبلغ المطلوب', 'سبب الرفض', 'تاريخ المراجعة']
                : ['الموظف', 'المبلغ المعتمد', 'تاريخ الاكتمال']);

        $rows = $q->get()->map(function ($a) use ($status) {
            return match ($status) {
                SalaryAdvance::PENDING  => [$a->user?->name, number_format((float) $a->requested_amount, 2), $a->reason, $a->request_date->toDateString()],
                SalaryAdvance::REJECTED => [$a->user?->name, number_format((float) $a->requested_amount, 2), $a->rejection_reason ?? '-', $a->reviewed_at?->format('Y-m-d') ?? '-'],
                default                 => [$a->user?->name, number_format((float) $a->approved_amount, 2), $a->completed_at?->format('Y-m-d') ?? '-'],
            };
        })->all();

        return ['title' => $title, 'columns' => $columns, 'rows' => $rows];
    }

    private function advanceDeductions(array $f): array
    {
        $year  = (int) ($f['year'] ?? now()->year);
        $month = (int) ($f['month'] ?? now()->month);

        $rows = SalaryAdvanceInstallment::with('advance.user:id,name')
            ->where('status', SalaryAdvanceInstallment::PAID)
            ->where('period_year', $year)->where('period_month', $month)
            ->when(! empty($f['user_id']), fn ($q) => $q->whereHas('advance', fn ($a) => $a->where('user_id', (int) $f['user_id'])))
            ->get();

        return [
            'title'   => "خصومات السلف {$month}/{$year}",
            'columns' => ['الموظف', 'قيمة القسط', 'الرصيد المتبقي بعد الخصم', 'تاريخ الخصم'],
            'rows'    => $rows->map(fn ($i) => [
                $i->advance?->user?->name, number_format((float) $i->planned_amount, 2),
                number_format(max(0, $i->advance->remaining_balance), 2), $i->deducted_at?->format('Y-m-d') ?? '-',
            ])->all(),
        ];
    }

    /** Installments due THIS calendar month that are still pending (haven't been deducted yet). */
    private function advancesDueThisMonth(array $f): array
    {
        $now = Carbon::now();
        $rows = SalaryAdvanceInstallment::with('advance.user:id,name,shop_id', 'advance.user.primaryBranch:id,name')
            ->where('period_year', $now->year)->where('period_month', $now->month)
            ->where('status', SalaryAdvanceInstallment::PENDING)
            ->when(! empty($f['user_id']), fn ($q) => $q->whereHas('advance', fn ($a) => $a->where('user_id', (int) $f['user_id'])))
            ->get();

        return [
            'title'   => "أقساط السلف المستحقة هذا الشهر ({$now->month}/{$now->year})",
            'columns' => ['الموظف', 'الفرع', 'قيمة القسط', 'الرصيد المتبقي'],
            'rows'    => $rows->map(fn ($i) => [
                $i->advance?->user?->name, $i->advance?->user?->primaryBranch?->name ?? '-',
                number_format((float) $i->planned_amount, 2), number_format(max(0, $i->advance->remaining_balance), 2),
            ])->all(),
        ];
    }

    /** Full advance history for one employee, every status. */
    private function advancesByEmployee(array $f): array
    {
        $q = SalaryAdvance::with('user:id,name')
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', (int) $f['user_id']))
            ->latest();

        $statusLabels = ['pending' => 'قيد المراجعة', 'active' => 'نشطة', 'rejected' => 'مرفوضة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];

        return [
            'title'   => 'السجل الكامل لسلف الموظف',
            'columns' => ['الموظف', 'المبلغ المطلوب', 'المعتمد', 'المدفوع', 'المتبقي', 'الحالة', 'تاريخ الطلب'],
            'rows'    => $q->get()->map(fn ($a) => [
                $a->user?->name, number_format((float) $a->requested_amount, 2), $a->approved_amount ? number_format((float) $a->approved_amount, 2) : '-',
                number_format((float) $a->paid_amount, 2), number_format($a->remaining_balance, 2),
                $statusLabels[$a->status] ?? $a->status, $a->request_date->toDateString(),
            ])->all(),
        ];
    }

    /** Advances grouped by branch (primary branch of the requesting employee). */
    private function advancesByBranch(array $f): array
    {
        $q = SalaryAdvance::with(['user:id,name,shop_id', 'user.primaryBranch:id,name'])
            ->when(! empty($f['shop_id']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('shop_id', (int) $f['shop_id'])))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->latest();

        $statusLabels = ['pending' => 'قيد المراجعة', 'active' => 'نشطة', 'rejected' => 'مرفوضة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];

        return [
            'title'   => 'سلف الموظفين حسب الفرع',
            'columns' => ['الفرع', 'الموظف', 'المعتمد', 'المتبقي', 'الحالة'],
            'rows'    => $q->get()->map(fn ($a) => [
                $a->user?->primaryBranch?->name ?? '-', $a->user?->name,
                $a->approved_amount ? number_format((float) $a->approved_amount, 2) : '-',
                number_format($a->remaining_balance, 2), $statusLabels[$a->status] ?? $a->status,
            ])->all(),
        ];
    }

    private function modeLabel(?string $mode): string
    {
        return match ($mode) {
            'date_range' => 'فترة بداية/نهاية', 'fixed_amount' => 'مبلغ شهري ثابت',
            'fixed_months' => 'عدد أشهر ثابت', 'custom' => 'خطة مخصصة', default => '-',
        };
    }
}
