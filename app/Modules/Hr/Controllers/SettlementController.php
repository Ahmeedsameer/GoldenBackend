<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSettlement;
use Illuminate\Http\Request;

/**
 * Read-only browsing of Final Settlement documents — every row is an
 * immutable snapshot created once by EmployeeService::endEmployment() and
 * never recalculated here. Admin-only, same scope as the End Employment
 * action itself (see EmployeeController::endEmployment()).
 */
class SettlementController extends Controller
{
    /** GET /api/hr/settlements?search=&employment_status=&date_from=&date_to= */
    public function index(Request $request)
    {
        $query = EmployeeSettlement::with(['employee:id,name,email', 'preparedBy:id,name']);

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('settlement_number', 'like', "%{$term}%")
                  ->orWhereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$term}%")
                      ->orWhere('email', 'like', "%{$term}%"));
            });
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->employee_id);
        }
        if ($request->filled('employment_status') && in_array($request->employment_status, ['resigned', 'terminated'], true)) {
            $query->where('employment_status', $request->employment_status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('leaving_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('leaving_date', '<=', $request->date_to);
        }

        $perPage = min($request->integer('per_page', $request->integer('limit', 20)), 100);

        return response()->json(['message' => 'ok', 'data' => $query->latest()->paginate($perPage)]);
    }

    /**
     * GET /api/hr/settlements/{id} — the exact saved snapshot, verbatim.
     * Never recalculated from live payroll/commission/advance data.
     */
    public function show(string $id)
    {
        $settlement = EmployeeSettlement::with(['employee:id,name,email,role', 'preparedBy:id,name'])->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $settlement]);
    }
}
