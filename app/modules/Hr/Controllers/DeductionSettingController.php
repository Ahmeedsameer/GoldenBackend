<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HrDeductionSetting;
use App\Modules\Hr\Services\HrAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-configurable salary deduction rules. Values are never hardcoded.
 */
class DeductionSettingController extends Controller
{
    public function __construct(private HrAuditLogger $audit) {}

    /** GET /api/hr/deduction-settings */
    public function index()
    {
        return response()->json(['message' => 'ok', 'data' => HrDeductionSetting::orderBy('id')->get()]);
    }

    /** PUT /api/hr/deduction-settings/{id} */
    public function update(Request $request, string $id)
    {
        $setting = HrDeductionSetting::findOrFail($id);

        $data = $request->validate([
            'value'     => ['sometimes', 'numeric', 'min:0'],
            'mode'      => ['sometimes', Rule::in([HrDeductionSetting::MODE_DAILY_FRACTION, HrDeductionSetting::MODE_FIXED])],
            'is_active' => ['sometimes', 'boolean'],
            'label'     => ['sometimes', 'string', 'max:255'],
        ]);

        $before = $setting->only(['value', 'mode', 'is_active', 'label']);
        $setting->update($data);
        $this->audit->log('deduction_setting.changed', $setting, $before, $setting->only(array_keys($data)));

        return response()->json(['message' => 'تم تحديث إعداد الخصم', 'data' => $setting]);
    }
}
