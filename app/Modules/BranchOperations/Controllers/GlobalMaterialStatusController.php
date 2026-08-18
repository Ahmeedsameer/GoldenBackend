<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BranchOperations\Services\RequiredMaterialsService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

/**
 * Global Branch Material Status (admin, cross-branch) — both views are the
 * SAME RequiredMaterialsService already powering the manager's own-branch
 * Required Materials page (see RequiredMaterialsService::computeRows()),
 * just presented By Branch vs. By Material. No second inventory engine.
 */
class GlobalMaterialStatusController extends Controller
{
    public function __construct(private RequiredMaterialsService $service, private ReportExportService $exportService) {}

    /** GET /api/admin/inventory/branch-material-status/by-branch */
    public function byBranch()
    {
        return response()->json(['message' => 'ok', 'data' => $this->service->forAllBranches()]);
    }

    /** GET /api/admin/inventory/branch-material-status/by-material */
    public function byMaterial()
    {
        return response()->json(['message' => 'ok', 'data' => $this->service->byMaterial()]);
    }

    /** GET /api/admin/reports/branch-material-status?view=by-branch|by-material&format=pdf|excel */
    public function report(Request $request)
    {
        $view = $request->get('view', 'by-branch');

        if ($view === 'by-material') {
            $data = $this->service->byMaterial();
            $columns = ['المنتج', 'الفئة', 'مُسعّر؟', 'آخر سعر شراء', 'المورد'];
            $tableRows = array_map(fn ($r) => [$r['name'], $r['category'], $r['is_priced'] ? 'نعم' : 'لا', $r['last_purchase_price'], $r['supplier']['name'] ?? '—'], $data['rows']);
            $title = 'حالة المواد بالفروع — حسب المادة';
        } else {
            $data = $this->service->forAllBranches();
            $columns = ['الفرع', 'نفد المخزون', 'مخزون منخفض', 'يحتاج تسعير', 'طلبات معلقة', 'مستلم اليوم'];
            $tableRows = array_map(fn ($b) => [
                $b['shop_name'], $b['summary']['out_of_stock'], $b['summary']['low_stock'],
                $b['summary']['needs_pricing'], $b['summary']['pending_requests'], $b['summary']['received_today'],
            ], $data['branches']);
            $title = 'حالة المواد بالفروع — حسب الفرع';
        }

        if ($request->filled('format')) {
            return $request->input('format') === 'excel'
                ? $this->exportService->excel($title, $columns, $tableRows)
                : $this->exportService->pdf($title, $columns, $tableRows);
        }

        return response()->json(['message' => 'ok', 'data' => $data]);
    }
}
