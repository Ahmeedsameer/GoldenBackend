<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Hr\Requests\StoreEmployeeRequest;
use App\Modules\Hr\Requests\UpdateEmployeeRequest;
use App\Modules\Hr\Services\ActiveBranchService;
use App\Modules\Hr\Services\CommissionService;
use App\Modules\Hr\Services\EmployeeService;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin-only employee management (create/edit HR profile, branches, commissions).
 */
class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeService $employees,
        private CommissionService $commissions,
        private ActiveBranchService $activeBranch,
    ) {}

    /** GET /api/hr/employees */
    public function index(Request $request)
    {
        $query = User::query()
            ->whereIn('role', ['manager', 'sales'])
            ->with('primaryBranch:id,name');

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"));
        }
        if ($request->filled('role') && in_array($request->role, ['manager', 'sales'])) {
            $query->where('role', $request->role);
        }
        if ($request->filled('status') && in_array($request->status, ['active', 'inactive'])) {
            $query->where('status', $request->status);
        }
        if ($request->filled('shop_id')) {
            $query->where('shop_id', (int) $request->shop_id);
        }

        $perPage = min($request->integer('per_page', $request->integer('limit', 20)), 100);
        $employees = $query->orderBy('name')->paginate($perPage);

        return response()->json(['message' => 'ok', 'data' => $employees]);
    }

    /** GET /api/hr/employees/{id} — full profile + current-month commission preview. */
    public function show(string $id)
    {
        $employee = User::with('primaryBranch:id,name')
            ->whereIn('role', ['manager', 'sales'])
            ->findOrFail($id);

        [$from, $to] = $this->currentPeriod();
        $personal = $this->commissions->personalCommission($employee, $from, $to);
        $branch   = $this->commissions->branchCommission($employee, $from, $to);

        $estimatedSalary = round(
            (float) $employee->base_salary + $personal['amount'] + $branch['total'],
            2,
        );

        // Where is the employee active right now (primary vs a live transfer)?
        $activeBranchId = $this->activeBranch->activeBranchId($employee);
        $activeBranch   = $activeBranchId ? Shop::find($activeBranchId, ['id', 'name']) : null;

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'employee'      => $employee,
                'active_branch' => $activeBranch,
                'is_transferred'=> $activeBranchId !== ($employee->shop_id ? (int) $employee->shop_id : null),
                'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'commission'    => [
                    'personal'         => $personal,
                    'branch'           => $branch,
                    'estimated_salary' => $estimatedSalary,
                ],
            ],
        ]);
    }

    /** POST /api/hr/employees */
    public function store(StoreEmployeeRequest $request)
    {
        $employee = $this->employees->create($request->validated());

        return response()->json([
            'message' => 'تم إنشاء الموظف بنجاح',
            'data'    => $employee,
        ], 201);
    }

    /** PUT /api/hr/employees/{id} */
    public function update(UpdateEmployeeRequest $request, string $id)
    {
        $employee = User::whereIn('role', ['manager', 'sales'])->findOrFail($id);
        $employee = $this->employees->update($employee, $request->validated());

        return response()->json([
            'message' => 'تم تحديث بيانات الموظف بنجاح',
            'data'    => $employee,
        ]);
    }

    /** PUT /api/hr/employees/{id}/toggle-status */
    public function toggleStatus(string $id)
    {
        $employee = User::whereIn('role', ['manager', 'sales'])->findOrFail($id);
        $employee = $this->employees->toggleStatus($employee);

        return response()->json([
            'message' => 'تم تغيير حالة الموظف',
            'data'    => ['id' => $employee->id, 'status' => $employee->status],
        ]);
    }

    private function currentPeriod(): array
    {
        $now = Carbon::now();
        return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }
}
