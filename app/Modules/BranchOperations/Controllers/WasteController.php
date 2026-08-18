<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Modules\BranchOperations\Services\WasteService;
use Illuminate\Http\Request;

class WasteController extends Controller
{
    public function __construct(private WasteService $service) {}

    public function index(Request $request)
    {
        $q = WasteRecord::with(['shop:id,name', 'product:id,name,sku', 'user:id,name']);

        $user = $request->user();
        if (in_array($user->role, ['manager', 'sales'], true) && $user->shop_id) {
            $q->where('shop_id', $user->shop_id);
        }
        if ($request->filled('shop_id')) {
            $q->where('shop_id', (int) $request->get('shop_id'));
        }
        if ($request->filled('reason')) {
            $q->where('reason', $request->get('reason'));
        }
        if ($request->filled('from') && $request->filled('to')) {
            $q->whereBetween('date', [$request->get('from'), $request->get('to')]);
        }
        if ($request->filled('search')) {
            $term = $request->get('search');
            $q->where(function ($sq) use ($term) {
                $sq->where('id', 'like', "%{$term}%")
                   ->orWhereHas('product', function ($pq) use ($term) {
                       $pq->where('name', 'like', "%{$term}%")
                          ->orWhere('sku', 'like', "%{$term}%");
                   });
            });
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $result = $q->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|in:' . implode(',', WasteRecord::REASONS),
            'notes' => 'nullable|string',
        ]);

        $record = $this->service->register($data, $request->user());

        return response()->json(['message' => 'تم تسجيل الهالك بنجاح', 'data' => $record->load(['shop:id,name', 'product:id,name,sku'])], 201);
    }
}
