<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Shop;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(private LeaveService $leaves) {}

    // ── Employee self-service ─────────────────────────────────

    /** POST /api/hr/leaves — employee submits their own request. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'type'       => ['nullable', 'in:annual,unpaid,sick'],
            'reason'     => ['nullable', 'string'],
        ]);

        $leave = $this->leaves->create($request->user(), $data);

        return response()->json(['message' => 'تم إرسال طلب الإجازة', 'data' => $leave], 201);
    }

    /** GET /api/hr/leaves/mine — own requests + current balance. */
    public function mine(Request $request)
    {
        $user = $request->user();
        $rows = LeaveRequest::where('user_id', $user->id)->latest()->paginate(20);

        return response()->json([
            'message' => 'ok',
            'data'    => ['balance' => $this->leaves->balance($user), 'requests' => $rows],
        ]);
    }

    /** PUT /api/hr/leaves/{id}/cancel — employee cancels their OWN still-pending request. */
    public function cancel(Request $request, string $id)
    {
        $leave = LeaveRequest::where('user_id', $request->user()->id)->findOrFail($id);
        $leave = $this->leaves->cancel($leave);

        return response()->json(['message' => 'تم إلغاء طلب الإجازة', 'data' => $leave]);
    }

    // ── Admin / Manager review ────────────────────────────────

    /** GET /api/hr/leaves — all requests (admin) or branch requests (manager). */
    public function index(Request $request)
    {
        $query = LeaveRequest::with('user:id,name,email,shop_id');

        if ($request->user()->role === 'manager') {
            $branchId = Shop::where('manager_id', $request->user()->id)->value('id') ?? $request->user()->shop_id;
            $query->whereHas('user', fn ($q) => $q->where('shop_id', $branchId));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['message' => 'ok', 'data' => $query->latest()->paginate(20)]);
    }

    /** PUT /api/hr/leaves/{id}/approve — admin only. */
    public function approve(Request $request, string $id)
    {
        $leave = LeaveRequest::where('status', 'pending')->findOrFail($id);
        $leave = $this->leaves->approve($leave, $request->input('note'));

        return response()->json(['message' => 'تمت الموافقة على الإجازة', 'data' => $leave]);
    }

    /** PUT /api/hr/leaves/{id}/reject — admin only. */
    public function reject(Request $request, string $id)
    {
        $leave = LeaveRequest::where('status', 'pending')->findOrFail($id);
        $leave = $this->leaves->reject($leave, $request->input('note'));

        return response()->json(['message' => 'تم رفض الإجازة', 'data' => $leave]);
    }

    /**
     * PUT /api/hr/leaves/{id}/end-early — admin: any employee.
     * Manager: only employees whose branch they manage, and NEVER another
     * manager's leave (only Admin can end a Branch Manager's leave).
     */
    public function endEarly(Request $request, string $id)
    {
        $leave = LeaveRequest::with('user')->findOrFail($id);
        $actor = $request->user();

        if ($actor->role === 'manager') {
            $employee = $leave->user;
            if ($employee->role === 'manager') {
                throw ValidationException::withMessages(['leave' => 'لا يمكن لمدير فرع إنهاء إجازة مدير آخر — يلزم صلاحية المدير العام.']);
            }
            $branchId = Shop::where('manager_id', $actor->id)->value('id') ?? $actor->shop_id;
            if (! $branchId || (int) $employee->shop_id !== (int) $branchId) {
                throw ValidationException::withMessages(['leave' => 'يمكنك إنهاء إجازة موظفي فرعك فقط.']);
            }
        }

        $data = $request->validate(['end_date' => ['required', 'date']]);
        $leave = $this->leaves->endEarly($leave, $data['end_date'], $actor);

        return response()->json(['message' => 'تم إنهاء الإجازة مبكرًا', 'data' => $leave]);
    }
}
