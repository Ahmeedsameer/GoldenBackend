<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeaveCashOut;
use App\Models\User;
use App\Modules\Hr\Services\LeaveCashOutService;
use Illuminate\Http\Request;

/**
 * Admin Leave Encashment — converts part of an employee's carry-over leave
 * balance into money. Admin-only create; every other role only ever sees
 * their OWN rows (self-service history).
 */
class LeaveCashOutController extends Controller
{
    public function __construct(private LeaveCashOutService $service) {}

    /** GET /api/hr/leave-cash-outs?user_id= */
    public function index(Request $request)
    {
        $query = LeaveCashOut::with(['user:id,name', 'creator:id,name'])->latest('date');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return response()->json(['message' => 'ok', 'data' => $query->paginate(20)]);
    }

    /** POST /api/hr/leave-cash-outs */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'days'    => ['required', 'numeric', 'min:0.01'],
            'note'    => ['nullable', 'string'],
            'date'    => ['nullable', 'date'],
        ]);

        $employee = User::findOrFail($data['user_id']);
        $cashOut = $this->service->cashOut($employee, (float) $data['days'], $data['note'] ?? null, $data['date'] ?? null);

        return response()->json(['message' => 'تم تحويل رصيد الإجازة إلى نقد', 'data' => $cashOut], 201);
    }

    /** GET /api/hr/leave-cash-outs/mine — self-service, own rows only. */
    public function mine(Request $request)
    {
        $rows = LeaveCashOut::where('user_id', $request->user()->id)->latest('date')->paginate(20);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }
}
