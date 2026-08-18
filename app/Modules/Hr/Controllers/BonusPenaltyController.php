<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\Penalty;
use App\Models\User;
use App\Modules\Hr\Services\BonusPenaltyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual Bonuses & Penalties. Create/Edit/Delete is Admin-only; every other
 * role only ever sees their OWN rows (self-service listing endpoints).
 */
class BonusPenaltyController extends Controller
{
    public function __construct(private BonusPenaltyService $service) {}

    private function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'amount'  => ['required', 'numeric', 'min:0.01'],
            'reason'  => ['required', 'string', 'max:255'],
            'date'    => ['required', 'date'],
            'notes'   => ['nullable', 'string'],
        ];
    }

    // ── Bonuses (admin) ─────────────────────────────────────────

    /** GET /api/hr/bonuses */
    public function bonusIndex(Request $request)
    {
        $query = Bonus::with(['user:id,name', 'creator:id,name'])->latest('date');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        return response()->json(['message' => 'ok', 'data' => $query->paginate(20)]);
    }

    /** POST /api/hr/bonuses */
    public function bonusStore(Request $request)
    {
        $data = $request->validate($this->rules());
        $employee = User::findOrFail($data['user_id']);
        $bonus = $this->service->createBonus($employee, $data);

        return response()->json(['message' => 'تم إضافة المكافأة', 'data' => $bonus], 201);
    }

    /** PUT /api/hr/bonuses/{id} */
    public function bonusUpdate(Request $request, string $id)
    {
        $bonus = Bonus::findOrFail($id);
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'reason' => ['sometimes', 'string', 'max:255'],
            'date'   => ['sometimes', 'date'],
            'notes'  => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in([Bonus::ACTIVE, Bonus::CANCELLED])],
        ]);
        $bonus = $this->service->updateBonus($bonus, $data);

        return response()->json(['message' => 'تم تحديث المكافأة', 'data' => $bonus]);
    }

    /** DELETE /api/hr/bonuses/{id} */
    public function bonusDestroy(string $id)
    {
        $this->service->deleteBonus(Bonus::findOrFail($id));

        return response()->json(['message' => 'تم حذف المكافأة']);
    }

    /** GET /api/hr/bonuses/mine — self-service, own rows only. */
    public function bonusMine(Request $request)
    {
        $rows = Bonus::where('user_id', $request->user()->id)->latest('date')->paginate(20);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ── Penalties (admin) ────────────────────────────────────────

    /** GET /api/hr/penalties */
    public function penaltyIndex(Request $request)
    {
        $query = Penalty::with(['user:id,name', 'creator:id,name'])->latest('date');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        return response()->json(['message' => 'ok', 'data' => $query->paginate(20)]);
    }

    /** POST /api/hr/penalties */
    public function penaltyStore(Request $request)
    {
        $data = $request->validate($this->rules());
        $employee = User::findOrFail($data['user_id']);
        $penalty = $this->service->createPenalty($employee, $data);

        return response()->json(['message' => 'تم إضافة الخصم', 'data' => $penalty], 201);
    }

    /** PUT /api/hr/penalties/{id} */
    public function penaltyUpdate(Request $request, string $id)
    {
        $penalty = Penalty::findOrFail($id);
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'reason' => ['sometimes', 'string', 'max:255'],
            'date'   => ['sometimes', 'date'],
            'notes'  => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in([Penalty::ACTIVE, Penalty::CANCELLED])],
        ]);
        $penalty = $this->service->updatePenalty($penalty, $data);

        return response()->json(['message' => 'تم تحديث الخصم', 'data' => $penalty]);
    }

    /** DELETE /api/hr/penalties/{id} */
    public function penaltyDestroy(string $id)
    {
        $this->service->deletePenalty(Penalty::findOrFail($id));

        return response()->json(['message' => 'تم حذف الخصم']);
    }

    /** GET /api/hr/penalties/mine — self-service, own rows only. */
    public function penaltyMine(Request $request)
    {
        $rows = Penalty::where('user_id', $request->user()->id)->latest('date')->paginate(20);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }
}
