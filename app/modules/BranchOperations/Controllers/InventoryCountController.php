<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use App\Modules\BranchOperations\Services\InventoryCountService;
use Illuminate\Http\Request;

class InventoryCountController extends Controller
{
    public function __construct(private InventoryCountService $service) {}

    private function baseQuery(Request $request)
    {
        $q = InventoryCountSession::with(['shop:id,name', 'createdByUser:id,name', 'employees:id,name'])->withCount('items');

        $user = $request->user();
        if (in_array($user->role, ['manager', 'sales'], true) && $user->shop_id) {
            $q->where('shop_id', $user->shop_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }
        if ($request->filled('shop_id')) {
            $q->where('shop_id', (int) $request->get('shop_id'));
        }

        return $q;
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 25), 100);
        $result = $this->baseQuery($request)->orderByDesc('id')->paginate($perPage);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    public function show(Request $request, int $id)
    {
        $session = $this->baseQuery($request)->with('items.product')->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $session]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $session = $this->service->create((int) $data['shop_id'], $data['employee_ids'] ?? [], $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'تم إنشاء جلسة الجرد', 'data' => $session], 201);
    }

    public function recordCounts(Request $request, int $id)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:inventory_count_items,id',
            'items.*.physical_quantity' => 'required|numeric|min:0',
        ]);

        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->recordCounts($session, $data['items'], $request->user());

        return response()->json(['message' => 'تم تسجيل الكميات الفعلية', 'data' => $session]);
    }

    public function submitForReview(Request $request, int $id)
    {
        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->submitForReview($session, $request->user());

        return response()->json(['message' => 'تم إرسال الجلسة للمراجعة', 'data' => $session]);
    }

    public function setItemReason(Request $request, int $id, int $itemId)
    {
        $data = $request->validate(['reason' => 'required|string']);
        $session = InventoryCountSession::findOrFail($id);
        $item = $this->service->setItemReason($session, $itemId, $data['reason']);

        return response()->json(['message' => 'تم تحديث السبب', 'data' => $item]);
    }

    public function approve(Request $request, int $id)
    {
        $session = InventoryCountSession::findOrFail($id);
        $session = $this->service->approve($session, $request->user());

        return response()->json(['message' => 'تمت الموافقة على جلسة الجرد', 'data' => $session]);
    }

    public function adjustInventory(Request $request, int $id)
    {
        $session = InventoryCountSession::with('items.product')->findOrFail($id);
        $session = $this->service->adjustInventory($session, $request->user());

        return response()->json(['message' => 'تم تسوية المخزون بناءً على الجرد', 'data' => $session]);
    }
}
