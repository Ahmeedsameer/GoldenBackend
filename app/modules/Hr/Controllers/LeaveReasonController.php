<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeaveReason;
use App\Modules\Hr\Services\HrAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-configurable leave/attendance reasons — lets the company add reasons
 * (e.g. "ظرف عائلي", "موعد طبي") without any code change. Each reason
 * carries its own financial policy (deducts_leave_balance / deducts_salary /
 * deduction_mode / deduction_value), consumed by LeaveService::approve() and
 * PayrollService::computeComponents(). No reason NAME is ever special-cased
 * in business logic — only these configured fields are read.
 */
class LeaveReasonController extends Controller
{
    public function __construct(private HrAuditLogger $audit) {}

    private function rules(bool $isUpdate = false): array
    {
        return [
            'name'                   => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'deducts_leave_balance'  => ['sometimes', 'boolean'],
            'deducts_salary'         => ['sometimes', 'boolean'],
            'deduction_mode'         => ['nullable', Rule::in([LeaveReason::MODE_DAILY_FRACTION, LeaveReason::MODE_FIXED])],
            'deduction_value'        => ['nullable', 'numeric', 'min:0'],
            'is_active'              => ['sometimes', 'boolean'],
        ];
    }

    /** GET /api/hr/leave-reasons — admin management view, every reason active or not. */
    public function index()
    {
        return response()->json(['message' => 'ok', 'data' => LeaveReason::orderBy('name')->get()]);
    }

    /** POST /api/hr/leave-reasons */
    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['created_by'] = auth()->id();
        // A reason with no financial effect at all has no meaningful mode/value.
        if (empty($data['deducts_salary'])) {
            $data['deduction_mode'] = null;
            $data['deduction_value'] = null;
        }

        $reason = LeaveReason::create($data);
        $this->audit->log('leave_reason.created', $reason, null, $reason->only(['name', 'deducts_leave_balance', 'deducts_salary', 'deduction_mode', 'deduction_value']));

        return response()->json(['message' => 'تم إنشاء العذر', 'data' => $reason], 201);
    }

    /** PUT /api/hr/leave-reasons/{id} */
    public function update(Request $request, string $id)
    {
        $reason = LeaveReason::findOrFail($id);
        $data = $request->validate($this->rules(true));
        if (array_key_exists('deducts_salary', $data) && ! $data['deducts_salary']) {
            $data['deduction_mode'] = null;
            $data['deduction_value'] = null;
        }

        $before = $reason->only(['name', 'deducts_leave_balance', 'deducts_salary', 'deduction_mode', 'deduction_value', 'is_active']);
        $reason->update($data);
        $this->audit->log('leave_reason.updated', $reason, $before, $reason->only(array_keys($before)));

        return response()->json(['message' => 'تم تحديث العذر', 'data' => $reason]);
    }

    /**
     * GET /api/hr/leave-reasons/active — self-service: active reasons only,
     * for the employee leave-request form. Includes the two YES/NO policy
     * flags only (enough for a plain-language "سيتم خصم..." sentence) —
     * never deduction_mode/deduction_value, which stay Admin-only internal
     * configuration.
     */
    public function active()
    {
        $reasons = LeaveReason::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'deducts_leave_balance', 'deducts_salary']);

        return response()->json(['message' => 'ok', 'data' => $reasons]);
    }
}
