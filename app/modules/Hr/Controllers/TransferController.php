<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTransfer;
use App\Modules\Hr\Requests\StoreTransferRequest;
use App\Modules\Hr\Services\TransferService;
use Illuminate\Http\Request;

/**
 * Admin management of employee temporary transfers.
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    /** GET /api/hr/transfers  (filter by status / user_id / branch) */
    public function index(Request $request)
    {
        $query = EmployeeTransfer::query()
            ->with(['user:id,name,email', 'primaryBranch:id,name', 'temporaryBranch:id,name', 'requestedBy:id,name', 'approvedBy:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        if ($request->filled('branch_id')) {
            $bid = (int) $request->branch_id;
            $query->where(fn ($q) => $q->where('temporary_branch_id', $bid)->orWhere('primary_branch_id', $bid));
        }

        $perPage = min($request->integer('per_page', $request->integer('limit', 20)), 100);

        return response()->json([
            'message' => 'ok',
            'data'    => $query->latest()->paginate($perPage),
        ]);
    }

    public function show(string $id)
    {
        $transfer = EmployeeTransfer::with([
            'user:id,name,email', 'primaryBranch:id,name', 'temporaryBranch:id,name',
            'requestedBy:id,name', 'approvedBy:id,name',
        ])->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $transfer]);
    }

    public function store(StoreTransferRequest $request)
    {
        $transfer = $this->transfers->create($request->validated());

        return response()->json(['message' => 'تم إنشاء طلب النقل (مسودّة)', 'data' => $transfer], 201);
    }

    public function update(StoreTransferRequest $request, string $id)
    {
        $transfer = EmployeeTransfer::findOrFail($id);
        $transfer = $this->transfers->update($transfer, $request->validated());

        return response()->json(['message' => 'تم تحديث النقل', 'data' => $transfer]);
    }

    public function approve(string $id)
    {
        $transfer = EmployeeTransfer::findOrFail($id);
        $transfer = $this->transfers->approve($transfer);

        return response()->json(['message' => 'تم اعتماد النقل', 'data' => $transfer]);
    }

    public function cancel(string $id)
    {
        $transfer = EmployeeTransfer::findOrFail($id);
        $transfer = $this->transfers->cancel($transfer);

        return response()->json(['message' => 'تم إلغاء النقل', 'data' => $transfer]);
    }
}
