<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Modules\Stock\Services\SupplierPaymentService;
use App\Modules\Stock\Services\SupplierService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

/**
 * Cross-supplier reports (per-supplier ledger lives at
 * SupplierController::ledger()). Every report queries live tables directly
 * (Supply/SupplierPayment/Supplier) — no separate reporting tables, same
 * convention as every other report in this app.
 */
class SupplierReportController extends Controller
{
    public function __construct(
        private SupplierService $supplierService,
        private SupplierPaymentService $paymentService,
        private ReportExportService $exportService,
    ) {}

    /** GET /api/stock/suppliers/reports/balances — every supplier's opening/invoiced/paid/outstanding. */
    public function balances(Request $request)
    {
        $rows = $this->supplierService->balancesSummary(false);

        if ($request->filled('format')) {
            return $this->exportBalances($rows, 'أرصدة الموردين');
        }

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    /** GET /api/stock/suppliers/reports/outstanding — suppliers with an outstanding_balance > 0 only. */
    public function outstanding(Request $request)
    {
        $rows = $this->supplierService->balancesSummary(true);

        if ($request->filled('format')) {
            return $this->exportBalances($rows, 'الموردون ذوو الأرصدة المستحقة');
        }

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    private function exportBalances(\Illuminate\Support\Collection $rows, string $title)
    {
        $columns = ['المورد', 'الهاتف', 'رصيد افتتاحي', 'إجمالي الفواتير', 'إجمالي المدفوع', 'رصيد الفواتير الحالية', 'الرصيد المستحق', 'عدد الفواتير'];
        $tableRows = $rows->map(fn ($r) => [
            $r['name'], $r['phone'], $r['opening_balance'], $r['total_invoiced'], $r['total_paid'],
            $r['current_credit'], $r['outstanding_balance'], $r['invoice_count'],
        ])->all();

        return request()->input('format') === 'excel'
            ? $this->exportService->excel($title, $columns, $tableRows, [], [2, 3, 4, 5, 6])
            : $this->exportService->pdf($title, $columns, $tableRows);
    }

    /** GET /api/stock/suppliers/reports/purchases — every purchase invoice across every supplier. */
    public function purchases(Request $request)
    {
        $query = Supply::query()->with(['supplier:id,name', 'items'])->latest('date')->latest('id');

        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));
        if ($request->filled('date_from'))   $query->whereDate('date', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))     $query->whereDate('date', '<=', $request->input('date_to'));

        $rows = $query->get()->map(fn (Supply $s) => [
            'id' => $s->id, 'invoice_number' => $s->invoice_number, 'supplier' => $s->supplier?->name,
            'date' => $s->date, 'items_subtotal' => $s->items_subtotal, 'discount' => (float) $s->discount,
            'tax' => (float) $s->tax, 'total_amount' => $s->total_amount, 'paid_amount' => (float) $s->paid_amount,
            'remaining_amount' => $s->remaining_amount, 'payment_status' => $s->payment_status,
        ]);

        if ($request->filled('format')) {
            $columns = ['رقم الفاتورة', 'المورد', 'التاريخ', 'الإجمالي الفرعي', 'الخصم', 'الضريبة', 'الإجمالي', 'المدفوع', 'المتبقي', 'الحالة'];
            $statusLabel = fn ($s) => ['paid' => 'مدفوعة', 'partial' => 'مدفوعة جزئياً', 'credit' => 'آجل'][$s] ?? $s;
            $tableRows = $rows->map(fn ($r) => [
                $r['invoice_number'], $r['supplier'], $r['date'], $r['items_subtotal'], $r['discount'],
                $r['tax'], $r['total_amount'], $r['paid_amount'], $r['remaining_amount'], $statusLabel($r['payment_status']),
            ])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير المشتريات', $columns, $tableRows, [], [3, 4, 5, 6, 7, 8])
                : $this->exportService->pdf('تقرير المشتريات', $columns, $tableRows);
        }

        return response()->json(['message' => 'ok', 'data' => $rows->values()]);
    }

    /** GET /api/stock/suppliers/reports/payments — every supplier payment (Payment History). */
    public function payments(Request $request)
    {
        $filters = $request->only(['supplier_id', 'supply_id', 'date_from', 'date_to']);
        $page = $this->paymentService->list($filters, (int) $request->input('per_page', 25));

        if ($request->filled('format')) {
            $columns = ['التاريخ', 'المورد', 'رقم الفاتورة', 'المبلغ', 'العملة', 'الخزنة', 'بواسطة', 'ملاحظات'];
            $tableRows = collect($page->items())->map(fn ($p) => [
                $p->date, $p->supplier?->name, $p->supply?->invoice_number, (float) $p->amount,
                $p->currency?->code, $p->safe?->shop?->name ?? 'الخزنة الرئيسية', $p->user?->name, $p->note,
            ])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('سجل مدفوعات الموردين', $columns, $tableRows, [], [3])
                : $this->exportService->pdf('سجل مدفوعات الموردين', $columns, $tableRows);
        }

        return response()->json(['message' => 'ok', 'data' => $page]);
    }
}
