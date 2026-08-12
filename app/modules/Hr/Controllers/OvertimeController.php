<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Services\ShiftAccessService;
use Illuminate\Http\Request;

/**
 * Admin-granted Overtime. Create/Cancel is Admin-only; every other role only
 * ever sees their OWN rows (self-service listing + shift-status endpoints).
 */
class OvertimeController extends Controller
{
    public function __construct(
        private OvertimeService $service,
        private ShiftAccessService $shiftAccess,
    ) {}

    private function rules(): array
    {
        return [
            'user_id'     => ['required', 'exists:users,id'],
            'date'        => ['required', 'date'],
            'start_time'  => ['required', 'date_format:H:i'],
            'end_time'    => ['required', 'date_format:H:i', 'after:start_time'],
            'hourly_rate' => ['required', 'numeric', 'min:0.01'],
            'reason'      => ['nullable', 'string', 'max:255'],
            'notes'       => ['nullable', 'string'],
        ];
    }

    // ── Overtime (admin) ─────────────────────────────────────────

    /** GET /api/hr/overtime?user_id=&year=&month= */
    public function index(Request $request)
    {
        $query = OvertimeRequest::with(['user:id,name', 'creator:id,name'])->latest('date');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('year')) {
            $query->whereYear('date', $request->integer('year'));
        }
        if ($request->filled('month')) {
            $query->whereMonth('date', $request->integer('month'));
        }
        return response()->json(['message' => 'ok', 'data' => $query->paginate(20)]);
    }

    /** POST /api/hr/overtime */
    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $employee = User::findOrFail($data['user_id']);
        $overtime = $this->service->create($employee, $data);

        return response()->json(['message' => 'تم منح العمل الإضافي', 'data' => $overtime], 201);
    }

    /** DELETE /api/hr/overtime/{id} */
    public function destroy(string $id)
    {
        $this->service->cancel(OvertimeRequest::findOrFail($id));

        return response()->json(['message' => 'تم إلغاء العمل الإضافي']);
    }

    /** GET /api/hr/overtime/mine — self-service, own rows only. */
    public function mine(Request $request)
    {
        $rows = OvertimeRequest::where('user_id', $request->user()->id)->latest('date')->paginate(20);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    /** GET /api/hr/me/shift-status — self-service: OFF_SHIFT | WORKING | OVERTIME, right now. */
    public function myShiftStatus(Request $request)
    {
        $result = $this->shiftAccess->statusFor($request->user()->id);

        return response()->json(['message' => 'ok', 'data' => [
            'status'   => strtoupper($result['status']),
            'shift'    => $result['shift'],
            'overtime' => $result['overtime'],
        ]]);
    }
}
