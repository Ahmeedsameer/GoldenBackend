<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Services\RequiredMaterialsService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

/**
 * Branch Required Materials — one shared read-only aggregate
 * (RequiredMaterialsService::forBranch()) surfaced two ways: a manager's own
 * branch (index, no shop_id needed) and an admin cross-branch report (report,
 * shop_id required, with PDF/Excel export like every other admin report).
 */
class RequiredMaterialsController extends Controller
{
    public function __construct(private RequiredMaterialsService $service, private ReportExportService $exportService) {}

    /** GET /api/branch-operations/required-materials — manager, own branch only. */
    public function index(Request $request)
    {
        $shopId = $request->user()->shop_id;
        if (! $shopId) {
            abort(422, 'المدير غير مرتبط بأي فرع');
        }

        return response()->json(['message' => 'ok', 'data' => $this->service->forBranch((int) $shopId)]);
    }

    /** GET /api/admin/reports/required-materials?shop_id=&format=pdf|excel — admin, any branch. */
    public function report(Request $request)
    {
        $shopId = $request->integer('shop_id');
        if (! $shopId) {
            abort(422, 'يرجى اختيار الفرع');
        }

        $data = $this->service->forBranch($shopId);

        if ($request->filled('format')) {
            $columns = ['الحالة', 'المنتج', 'الفئة', 'رصيد الفرع', 'رصيد المخزن الرئيسي', 'الحد الأدنى', 'آخر سعر شراء'];
            $labels = ['out_of_stock' => 'نفد المخزون', 'low_stock' => 'مخزون منخفض', 'needs_pricing' => 'يحتاج تسعير'];
            $tableRows = [];
            foreach (['out_of_stock', 'low_stock', 'needs_pricing'] as $group) {
                foreach ($data[$group] as $r) {
                    $tableRows[] = [$labels[$group], $r['name'], $r['category'], $r['branch_qty'], $r['warehouse_qty'], $r['minimum_quantity'], $r['last_purchase_price']];
                }
            }

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('المواد المطلوبة', $columns, $tableRows)
                : $this->exportService->pdf('المواد المطلوبة', $columns, $tableRows);
        }

        return response()->json(['message' => 'ok', 'data' => $data]);
    }
}
