<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\ScheduleService;
use App\Support\ArabicPdfFont;
use App\Support\ArabicShaper;
use App\Support\PdfPageFooter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    /** GET /api/hr/schedule?date=YYYY-MM-DD&shop_id=&role=&user_id=&status= */
    public function roster(Request $request)
    {
        [$from, $to] = $this->schedule->weekBounds($request->get('date'));
        $roster = $this->schedule->weekRoster($from, $to, $this->scopedFilters($request));

        return response()->json(['message' => 'ok', 'data' => $roster]);
    }

    /** GET /api/hr/schedule/mine?date= — the authenticated employee's own PUBLISHED schedule only. */
    public function mine(Request $request)
    {
        [$from, $to] = $this->schedule->weekBounds($request->get('date'));
        $roster = $this->schedule->weekRoster($from, $to, ['user_id' => $request->user()->id], onlyPublished: true);

        return response()->json(['message' => 'ok', 'data' => $roster]);
    }

    /** A manager is always scoped to their own branch; admin may pick any branch. */
    private function scopedFilters(Request $request): array
    {
        $filters = $request->only(['shop_id', 'role', 'user_id', 'status']);

        if ($request->user()->role === 'manager') {
            $managed = Shop::where('manager_id', $request->user()->id)->value('id');
            $filters['shop_id'] = $managed ?: $request->user()->shop_id;
        }

        return $filters;
    }

    /** PUT /api/hr/schedule — set/replace one cell. */
    public function upsert(Request $request)
    {
        $data = $this->validateEntry($request);
        $this->assertManagerCanEdit($request, $data['user_id']);

        $entry = $this->schedule->upsertEntry($data['user_id'], $data['date'], $data);

        return response()->json(['message' => 'تم حفظ الخلية', 'data' => $entry]);
    }

    /**
     * PUT /api/hr/schedule/bulk — save the WHOLE week in one request.
     * Body: { entries: [{ user_id, date, type, shift_template_id?, start_time?, end_time?, shop_id?, notes? }] }
     */
    public function bulkUpsert(Request $request)
    {
        $payload = $request->validate([
            'entries'                     => ['required', 'array', 'min:1'],
            'entries.*.user_id'           => ['required', 'integer', 'exists:users,id'],
            'entries.*.date'              => ['required', 'date'],
            'entries.*.type'              => ['required', Rule::in(ScheduleEntry::TYPES)],
            'entries.*.shift_template_id' => ['nullable', 'integer', 'exists:shift_templates,id'],
            'entries.*.start_time'        => ['nullable', 'date_format:H:i'],
            'entries.*.end_time'          => ['nullable', 'date_format:H:i'],
            'entries.*.shop_id'           => ['nullable', 'integer', 'exists:shops,id'],
            'entries.*.notes'             => ['nullable', 'string'],
        ]);

        if ($request->user()->role === 'manager') {
            $managed = Shop::where('manager_id', $request->user()->id)->value('id') ?: $request->user()->shop_id;
            $userIds = collect($payload['entries'])->pluck('user_id')->unique();
            $outside = User::whereIn('id', $userIds)->where('shop_id', '!=', $managed)->exists();
            if ($outside) {
                return response()->json(['message' => 'لا يمكنك تعديل جدول موظفين خارج فرعك.'], 403);
            }
        }

        $result = $this->schedule->bulkUpsert($payload['entries']);

        $msg = "تم حفظ {$result['saved']} خلية";
        if (count($result['skipped'])) {
            $msg .= ' — تم تجاهل ' . count($result['skipped']) . ' خلية ضمن فترة نقل مؤقت';
        }

        return response()->json(['message' => $msg, 'data' => $result]);
    }

    // ── Bulk Shift Assignment ────────────────────────────────────────────────
    // Purely additive on top of the existing single-cell (upsert) and
    // whole-week (bulkUpsert) save paths above — neither of which is touched.
    // This lets an admin/manager assign ONE shift to MANY employees on ONE
    // date from a dedicated modal, with an explicit skip/replace choice for
    // employees who already have an entry that day (never a silent overwrite).

    /**
     * GET /api/hr/schedule/bulk-assign/employees?shop_id=&role=&search=
     * The employee picker's data source — same scoping rule as the roster
     * grid (scopedFilters() below forces a manager to their own branch),
     * reusing ScheduleService::scopedEmployees() so "who can be scheduled"
     * is never computed two different ways in this codebase.
     */
    public function assignableEmployees(Request $request)
    {
        $filters = $this->scopedFilters($request);
        if ($request->filled('search')) {
            $filters['search'] = $request->string('search')->toString();
        }

        $employees = $this->schedule->scopedEmployees($filters)->map(fn (User $e) => [
            'id' => $e->id, 'name' => $e->name, 'role' => $e->role,
            'shop_id' => $e->shop_id, 'primary_branch' => $e->primaryBranch?->name,
        ]);

        return response()->json(['message' => 'ok', 'data' => $employees->values()]);
    }

    /**
     * GET /api/hr/schedule/bulk-assign/conflicts?employee_ids[]=&date=
     * Read-only preview so the modal can show "N موظفين لديهم شيفت مسجل
     * بالفعل" and let the admin/manager choose skip vs. replace BEFORE
     * anything is written — never a silent overwrite.
     */
    public function bulkAssignConflicts(Request $request)
    {
        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'date'           => ['required', 'date'],
        ]);

        $this->assertManagerCanEditBulk($request, $data['employee_ids']);

        $conflicts = $this->schedule->findExistingEntriesForDate($data['employee_ids'], $data['date']);

        return response()->json(['message' => 'ok', 'data' => ['conflicts' => $conflicts, 'count' => count($conflicts)]]);
    }

    /**
     * POST /api/hr/schedule/bulk-assign
     * Body: { employee_ids: number[], shift_id: number, date: string, replace_existing?: bool }
     * ONE endpoint for the whole operation — never one request per employee.
     * Branch/authorization is re-verified here from the DB regardless of what
     * the frontend already filtered by (see assertManagerCanEditBulk) — a
     * tampered request naming an out-of-branch employee is rejected exactly
     * like the existing bulkUpsert() endpoint already does.
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'employee_ids'      => ['required', 'array', 'min:1'],
            'employee_ids.*'    => ['integer', 'exists:users,id'],
            'shift_id'          => ['required', 'integer', 'exists:shift_templates,id'],
            'date'              => ['required', 'date'],
            'replace_existing'  => ['nullable', 'boolean'],
        ]);

        $this->assertManagerCanEditBulk($request, $data['employee_ids']);

        $template = ShiftTemplate::findOrFail($data['shift_id']);
        $result = $this->schedule->bulkAssignShift(
            $data['employee_ids'],
            $template,
            $data['date'],
            (bool) ($data['replace_existing'] ?? false),
        );

        $msg = "تم تعيين الشيفت لـ {$result['assigned']} موظف";
        if (count($result['skipped_existing'])) {
            $msg .= ' — تم تخطي ' . count($result['skipped_existing']) . ' موظف لديهم شيفت مسجل بالفعل';
        }
        if (count($result['skipped_transferred'])) {
            $msg .= ' — تم تجاهل ' . count($result['skipped_transferred']) . ' موظف ضمن فترة نقل مؤقت';
        }

        return response()->json(['message' => $msg, 'data' => $result]);
    }

    /**
     * Same rule as bulkUpsert()'s inline manager check (line ~84 above) and
     * assertManagerCanEdit() — re-derives the manager's own branch from the
     * DB and rejects if ANY requested employee belongs to a different one.
     * Never trusts a shop_id the client may have sent; always resolves the
     * employees' real shop_id fresh from the users table.
     */
    private function assertManagerCanEditBulk(Request $request, array $employeeIds): void
    {
        if ($request->user()->role !== 'manager') {
            return;
        }
        $managed = Shop::where('manager_id', $request->user()->id)->value('id') ?: $request->user()->shop_id;
        $outside = User::whereIn('id', $employeeIds)->where('shop_id', '!=', $managed)->exists();
        if ($outside) {
            abort(403, 'لا يمكنك تعيين شيفت لموظفين خارج فرعك.');
        }
    }

    /** POST /api/hr/schedule/publish — publish (or re-publish) the week; notifies affected employees. */
    public function publish(Request $request)
    {
        [$from, $to] = $this->schedule->weekBounds($request->get('date'));
        $count = $this->schedule->publishWeek($from, $to, $this->scopedFilters($request));

        return response()->json(['message' => "تم نشر الجدول ({$count} خلية)", 'data' => ['published' => $count]]);
    }

    /** POST /api/hr/schedule/cancel — retract publication; notifies affected employees. */
    public function cancel(Request $request)
    {
        [$from, $to] = $this->schedule->weekBounds($request->get('date'));
        $count = $this->schedule->cancelWeek($from, $to, $this->scopedFilters($request));

        return response()->json(['message' => "تم إلغاء نشر الجدول ({$count} خلية)", 'data' => ['cancelled' => $count]]);
    }

    /** GET /api/hr/schedule/export?date=&format=pdf */
    public function export(Request $request)
    {
        [$from, $to] = $this->schedule->weekBounds($request->get('date'));
        $roster = $this->schedule->weekRoster($from, $to, $this->scopedFilters($request));

        $typeLabels = $this->typeLabels();
        $company = CompanySetting::current();

        // dompdf cannot join/reorder Arabic glyphs itself (see ArabicShaper) —
        // shape a COPY for the PDF only; $roster/$typeLabels are also used
        // for the JSON view, which must keep plain logical Arabic text.
        $shapedRoster = ArabicShaper::reverseRosterDays(ArabicShaper::shapeDeep($roster));
        $shapedTypeLabels = ArabicShaper::shapeDeep($typeLabels);
        $companyDisplay = ArabicShaper::companyDisplay($company);
        $metaLine = ArabicShaper::shape(
            "{$roster['from']} → {$roster['to']} · {$company->name} — الموارد البشرية · " . now()->format('Y-m-d H:i')
        );

        $pdf = Pdf::loadView('hr.schedule', [
            'roster' => $shapedRoster,
            'typeLabels' => $shapedTypeLabels,
            'company' => $company,
            'companyDisplay' => $companyDisplay,
            'metaLine' => $metaLine,
        ])->setPaper('a3', 'landscape');
        ArabicPdfFont::apply($pdf);
        PdfPageFooter::apply($pdf);

        return $pdf->download("weekly-schedule-{$roster['from']}.pdf");
    }

    private function typeLabels(): array
    {
        return [
            'work' => 'عمل', 'leave' => 'إجازة', 'off_day' => 'يوم إجازة أسبوعية',
            'holiday' => 'عطلة رسمية', 'sick_leave' => 'إجازة مرضية', 'training' => 'تدريب',
            'business_trip' => 'مهمة عمل', 'absent' => 'غياب', 'late' => 'تأخير', 'half_day' => 'نصف يوم',
            'transferred' => 'منقول مؤقتًا',
        ];
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'user_id'            => ['required', 'integer', 'exists:users,id'],
            'date'               => ['required', 'date'],
            'type'               => ['required', Rule::in(ScheduleEntry::TYPES)],
            'shift_template_id'  => ['nullable', 'integer', 'exists:shift_templates,id'],
            'start_time'         => ['nullable', 'date_format:H:i'],
            'end_time'           => ['nullable', 'date_format:H:i'],
            'shop_id'            => ['nullable', 'integer', 'exists:shops,id'],
            'notes'              => ['nullable', 'string'],
        ]);
    }

    private function assertManagerCanEdit(Request $request, int $employeeUserId): void
    {
        if ($request->user()->role !== 'manager') {
            return;
        }
        $managed = Shop::where('manager_id', $request->user()->id)->value('id') ?: $request->user()->shop_id;
        $employeeShop = User::whereKey($employeeUserId)->value('shop_id');
        if ($employeeShop !== $managed) {
            abort(403, 'لا يمكنك تعديل جدول موظف خارج فرعك.');
        }
    }

    // ── Shift templates ─────────────────────────────────────────

    /** GET /api/hr/shift-templates */
    public function shiftTemplates()
    {
        $templates = ShiftTemplate::where('is_active', true)->orderBy('start_time')->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'color'       => $t->color,
                'description' => $t->description,
                'start_time'  => substr($t->start_time, 0, 5),
                'end_time'    => substr($t->end_time, 0, 5),
            ]);

        return response()->json(['message' => 'ok', 'data' => $templates]);
    }

    /** POST /api/hr/shift-templates */
    public function storeShiftTemplate(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'color'       => ['nullable', 'string', 'max:30'],
            'start_time'  => ['required', 'date_format:H:i'],
            'end_time'    => ['required', 'date_format:H:i'],
            'description' => ['nullable', 'string'],
        ]);

        $tpl = ShiftTemplate::create($data);

        return response()->json(['message' => 'تم إنشاء الشيفت', 'data' => $tpl], 201);
    }

    /** GET /api/hr/shift-templates/all — admin management view: every template, active or archived. */
    public function allShiftTemplates()
    {
        $today = Carbon::today()->toDateString();
        $templates = ShiftTemplate::orderBy('name')->get()->map(function (ShiftTemplate $t) use ($today) {
            return [
                'id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'description' => $t->description,
                'start_time' => substr($t->start_time, 0, 5), 'end_time' => substr($t->end_time, 0, 5),
                'is_active' => $t->is_active,
                'used_in_future' => ScheduleEntry::where('shift_template_id', $t->id)->where('date', '>=', $today)->exists(),
            ];
        });

        return response()->json(['message' => 'ok', 'data' => $templates]);
    }

    /**
     * PUT /api/hr/shift-templates/{id}
     * `scope` controls how already-scheduled entries linked to this template are
     * re-synced: future (recommended, default) | unpublished | all. Published
     * past entries are never touched regardless of scope.
     */
    public function updateShiftTemplate(Request $request, string $id)
    {
        $template = ShiftTemplate::findOrFail($id);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'color'       => ['sometimes', 'string', 'max:30'],
            'start_time'  => ['sometimes', 'date_format:H:i'],
            'end_time'    => ['sometimes', 'date_format:H:i'],
            'description' => ['nullable', 'string'],
            'scope'       => ['sometimes', Rule::in(['future', 'unpublished', 'all'])],
        ]);
        $scope = $data['scope'] ?? 'future';
        unset($data['scope']);

        $template = $this->schedule->updateShiftTemplate($template, $data, $scope);

        return response()->json(['message' => 'تم تحديث الشيفت', 'data' => $template]);
    }

    /** PUT /api/hr/shift-templates/{id}/archive — disable without deleting historical records. */
    public function archiveShiftTemplate(string $id)
    {
        $template = $this->schedule->archiveShiftTemplate(ShiftTemplate::findOrFail($id));

        return response()->json(['message' => 'تم تعطيل الشيفت', 'data' => $template]);
    }

    /** PUT /api/hr/shift-templates/{id}/restore */
    public function restoreShiftTemplate(string $id)
    {
        $template = $this->schedule->restoreShiftTemplate(ShiftTemplate::findOrFail($id));

        return response()->json(['message' => 'تم تفعيل الشيفت', 'data' => $template]);
    }

    /** DELETE /api/hr/shift-templates/{id} — blocked if used by any schedule dated today or later. */
    public function destroyShiftTemplate(string $id)
    {
        $this->schedule->deleteShiftTemplate(ShiftTemplate::findOrFail($id));

        return response()->json(['message' => 'تم حذف الشيفت']);
    }
}
