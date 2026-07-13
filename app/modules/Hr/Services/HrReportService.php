<?php

namespace App\Modules\Hr\Services;

use App\Models\EmployeeTransfer;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Payroll;
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
    ) {}

    public function build(string $type, array $f): array
    {
        return match ($type) {
            'employee_sales'     => $this->employeeSales($f),
            'branch_sales'       => $this->branchSales($f),
            'attendance'         => $this->attendanceReport($f),
            'leaves'             => $this->leaves($f),
            'payroll'            => $this->payroll($f),
            'commissions'        => $this->commissions($f),
            'top_performers'     => $this->topPerformers($f),
            'branch_performance' => $this->branchPerformance($f),
            'monthly_comparison' => $this->monthlyComparison($f),
            'transfers'          => $this->transfers($f),
            'transfer_earnings'  => $this->transferEarnings($f),
            default              => ['title' => 'تقرير', 'columns' => [], 'rows' => []],
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
}
