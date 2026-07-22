<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use App\Modules\BranchOperations\Services\InventoryAdjustmentService;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function __construct(private InventoryAdjustmentService $service) {}

    private function baseQuery(Request $request)
    {
        $q = InventoryAdjustmentRequest::with(['shop:id,name', 'product:id,name,sku', 'requestedByUser:id,name', 'reviewedByUser:id,name']);

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'product_id' => 'required|integer|exists:products,id',
            'after_quantity' => 'required|numeric|min:0',
            'reason' => 'required|string',
        ]);

        $req = $this->service->request($data, $request->user());

        return response()->json(['message' => 'تم إنشاء طلب تسوية المخزون', 'data' => $req], 201);
    }

    public function approve(Request $request, int $id)
    {
        $req = InventoryAdjustmentRequest::findOrFail($id);
        $req = $this->service->approve($req, $request->user());

        return response()->json(['message' => 'تمت الموافقة على طلب التسوية', 'data' => $req]);
    }

    public function reject(Request $request, int $id)
    {
        $req = InventoryAdjustmentRequest::findOrFail($id);
        $req = $this->service->reject($req, $request->user());

        return response()->json(['message' => 'تم رفض طلب التسوية', 'data' => $req]);
    }

    public function execute(Request $request, int $id)
    {
        $req = InventoryAdjustmentRequest::findOrFail($id);
        $req = $this->service->execute($req, $request->user());

        return response()->json(['message' => 'تم تنفيذ تسوية المخزون', 'data' => $req]);
    }
}
