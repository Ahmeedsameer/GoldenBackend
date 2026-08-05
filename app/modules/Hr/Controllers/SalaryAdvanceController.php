<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\Shop;
use App\Modules\Hr\Services\SalaryAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalaryAdvanceController extends Controller
{
    public function __construct(private SalaryAdvanceService $advances) {}

    // ── Employee self-service ─────────────────────────────────

    /** POST /api/hr/advances — employee submits their own request. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason'           => ['required', 'string', 'max:255'],
            'notes'            => ['nullable', 'string'],
            'request_date'     => ['nullable', 'date'],
        ]);

        $advance = $this->advances->create($request->user(), $data);

        return response()->json(['message' => 'تم إرسال طلب السلفة', 'data' => $advance], 201);
    }

    /** GET /api/hr/advances/mine — own requests only, with full plan + repayment history. */
    public function mine(Request $request)
    {
        $rows = SalaryAdvance::where('user_id', $request->user()->id)
            ->with(['installments', 'repayments'])
            ->latest()->paginate(20);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ── Admin / Manager review ────────────────────────────────

    /**
     * GET /api/hr/advances — all requests (admin) or own-branch requests (manager, read-only).
     * Filters: status, shop_id (branch), user_id (employee), year+month (installment period), search (employee name/email).
     */
    public function index(Request $request)
    {
        $query = SalaryAdvance::with(['user:id,name,email,shop_id', 'user.primaryBranch:id,name', 'installments', 'payingSafe:id,shop_id', 'payingSafe.shop:id,name']);

        if ($request->user()->role === 'manager') {
            $branchId = Shop::where('manager_id', $request->user()->id)->value('id') ?? $request->user()->shop_id;
            $query->whereHas('user', fn ($q) => $q->where('shop_id', $branchId));
        } elseif ($request->filled('shop_id')) {
            $query->whereHas('user', fn ($q) => $q->where('shop_id', (int) $request->shop_id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        if ($request->filled('year') && $request->filled('month')) {
            $query->whereHas('installments', fn ($q) => $q
                ->where('period_year', (int) $request->year)->where('period_month', (int) $request->month));
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        }

        $rows = $query->latest()->paginate(20);
        $rows->getCollection()->transform(fn (SalaryAdvance $a) => $this->withComputed($a));

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    /** GET /api/hr/advances/{id} — full detail incl. installments + repayments. */
    public function show(Request $request, string $id)
    {
        $query = SalaryAdvance::with([
            'user:id,name,email,shop_id', 'user.primaryBranch:id,name', 'reviewer:id,name', 'installments',
            'payingSafe:id,shop_id', 'payingSafe.shop:id,name',
            'repayments.recordedBy:id,name', 'repayments.safe:id,shop_id', 'repayments.safe.shop:id,name',
        ]);

        if ($request->user()->role === 'manager') {
            $branchId = Shop::where('manager_id', $request->user()->id)->value('id') ?? $request->user()->shop_id;
            $query->whereHas('user', fn ($q) => $q->where('shop_id', $branchId));
        }

        return response()->json(['message' => 'ok', 'data' => $this->withComputed($query->findOrFail($id))]);
    }

    /** PUT /api/hr/advances/{id}/reject — admin only. */
    public function reject(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::PENDING)->findOrFail($id);
        $advance = $this->advances->reject($advance, $request->input('reason'), $request->user());

        return response()->json(['message' => 'تم رفض طلب السلفة', 'data' => $advance]);
    }

    /** PUT /api/hr/advances/{id}/approve — admin only; defines the installment plan in the same action. */
    public function approve(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::PENDING)->findOrFail($id);
        $plan = $this->validatePlan($request);

        $advance = $this->advances->approve($advance, $plan, $request->user());

        return response()->json(['message' => 'تمت الموافقة على السلفة', 'data' => $advance]);
    }

    /** PUT /api/hr/advances/{id}/cancel — admin only; only before any deduction/repayment has happened. */
    public function cancel(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::ACTIVE)->findOrFail($id);
        $advance = $this->advances->cancel($advance, $request->user());

        return response()->json(['message' => 'تم إلغاء السلفة', 'data' => $advance]);
    }

    /** PUT /api/hr/advances/{id}/plan — admin only; edits the still-pending part of an active advance's plan. */
    public function updatePlan(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::ACTIVE)->findOrFail($id);
        $plan = $this->validatePlan($request, requireApprovedAmount: false);

        $advance = $this->advances->updatePlan($advance, $plan);

        return response()->json(['message' => 'تم تحديث خطة التقسيط', 'data' => $advance]);
    }

    /** POST /api/hr/advances/{id}/repayments — admin only; record an early/manual repayment. */
    public function storeRepayment(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::ACTIVE)->findOrFail($id);
        $data = $request->validate([
            'amount'  => ['required', 'numeric', 'min:0.01'],
            'date'    => ['required', 'date'],
            'safe_id' => ['required', 'integer', 'exists:safes,id'],
            'notes'   => ['nullable', 'string'],
        ]);

        $advance = $this->advances->recordEarlyRepayment($advance, (float) $data['amount'], $data['date'], (int) $data['safe_id'], $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'تم تسجيل السداد المبكر', 'data' => $advance]);
    }

    /** PUT /api/hr/advances/{id}/default-safe — admin only; changes where FUTURE installments land. */
    public function changeDefaultSafe(Request $request, string $id)
    {
        $advance = SalaryAdvance::where('status', SalaryAdvance::ACTIVE)->findOrFail($id);
        $data = $request->validate(['safe_id' => ['required', 'integer', 'exists:safes,id']]);

        $advance = $this->advances->changeDefaultSafe($advance, (int) $data['safe_id'], $request->user());

        return response()->json(['message' => 'تم تغيير الخزنة الافتراضية للأقساط القادمة', 'data' => $advance]);
    }

    /** GET /api/hr/advances/{id}/transactions — every SafeTransaction linked to this advance (disbursement + repayments). */
    public function transactions(string $id)
    {
        $advance = SalaryAdvance::findOrFail($id);
        $rows = \App\Models\SafeTransaction::where('salary_advance_id', $advance->id)
            ->with(['safe.shop:id,name', 'safe.safeType:id,name', 'user:id,name'])
            ->latest()->get();

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    private function validatePlan(Request $request, bool $requireApprovedAmount = true): array
    {
        return $request->validate([
            'approved_amount' => [$requireApprovedAmount ? 'required' : 'sometimes', 'numeric', 'min:0.01'],
            // Part 6.1 — required only at approval time (updatePlan never touches the Safe).
            'safe_id'         => [$requireApprovedAmount ? 'required' : 'sometimes', 'integer', 'exists:safes,id'],
            'mode'            => ['required', Rule::in(['date_range', 'fixed_amount', 'fixed_months', 'custom'])],
            'monthly_amount'  => ['required_if:mode,fixed_amount', 'numeric', 'min:0.01'],
            'months'          => ['required_if:mode,fixed_months', 'integer', 'min:1', 'max:60'],
            'schedule'        => ['required_if:mode,custom', 'array', 'min:1'],
            'schedule.*'      => ['numeric', 'min:0.01'],
            'start_year'      => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'start_month'     => ['nullable', 'integer', 'min:1', 'max:12'],
            'end_year'        => ['required_if:mode,date_range', 'nullable', 'integer', 'min:2020', 'max:2100'],
            'end_month'       => ['required_if:mode,date_range', 'nullable', 'integer', 'min:1', 'max:12'],
        ]);
    }

    /** Attach Branch / next-installment / current-status / installment-count for list & detail views. */
    private function withComputed(SalaryAdvance $advance): SalaryAdvance
    {
        $next = $advance->installments
            ->where('status', SalaryAdvanceInstallment::PENDING)
            ->sortBy('sequence')
            ->first();

        $advance->setAttribute('branch_name', $advance->user?->primaryBranch?->name);
        $advance->setAttribute('installments_count', $advance->installments->count());
        $advance->setAttribute('next_installment', $next ? [
            'period_year' => $next->period_year, 'period_month' => $next->period_month, 'planned_amount' => $next->planned_amount,
        ] : null);
        $advance->setAttribute('current_status', $next?->effective_status ?? $advance->status);

        return $advance;
    }
}
