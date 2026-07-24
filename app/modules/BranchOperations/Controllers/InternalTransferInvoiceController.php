<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Services\WarehouseResolver;
use App\Support\ArabicPdfFont;
use App\Support\ArabicShaper;
use App\Support\PdfPageFooter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Phase 4.6 — the printable Internal Transfer Invoice. INTERNAL ONLY: it is
 * generated purely from transfer_requests/transfer_request_items/logs and
 * internal_transfer_invoices — never touches the sales `invoices` table, so
 * it structurally cannot appear in Sales/Revenue/Financial reports.
 */
class InternalTransferInvoiceController extends Controller
{
    public function __construct(private WarehouseResolver $warehouse) {}

    /** GET /branch-operations/transfers/{id}/invoice/pdf — same read-scope as TransferRequestController::show. */
    public function pdf(Request $request, int $id)
    {
        $user = $request->user();

        $query = TransferRequest::with([
            'sourceShop:id,name', 'destinationShop:id,name',
            'requestedByUser:id,name', 'approvedByUser:id,name',
            'items.product:id,name,sku,scalar,purchase_cost', 'items.batches',
            'logs.user:id,name', 'internalInvoice',
        ]);

        if (in_array($user->role, ['manager', 'sales'], true) && $user->shop_id) {
            $query->where(function ($q) use ($user) {
                $q->where('source_shop_id', $user->shop_id)->orWhere('destination_shop_id', $user->shop_id);
            });
        }

        $transfer = $query->findOrFail($id);

        if (! $transfer->internalInvoice) {
            abort(404, 'لا توجد فاتورة نقل داخلية لهذا الطلب — يجب اعتماد الطلب أولاً');
        }

        $shippedByLog = $transfer->logs->firstWhere('action', 'shipped');
        $receivedByLog = $transfer->logs->firstWhere('action', 'received');

        $statusLabels = [
            'draft' => 'مسودة', 'submitted' => 'بانتظار الموافقة', 'approved' => 'تمت الموافقة',
            'rejected' => 'مرفوض', 'preparing' => 'قيد التجهيز', 'shipped' => 'تم الشحن',
            'received' => 'تم الاستلام', 'closed' => 'مغلق',
        ];

        // Same labels used everywhere else priority is shown (transfer-create form, dashboards) —
        // the raw enum value ('normal', 'urgent', …) must never leak into a printed document.
        $priorityLabels = [
            'low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'مرتفعة', 'urgent' => 'عاجلة',
        ];

        // dompdf has no Arabic shaping/bidi engine (see ArabicShaper's own docblock) — EVERY
        // Arabic string must be pre-shaped before reaching the view, including static UI
        // labels. These used to be hardcoded directly in the blade file and rendered as
        // disconnected, wrong-order letterforms because they never went through
        // ArabicShaper::shape(); now every label is shaped here, exactly like the dynamic
        // values already were.
        $labels = ArabicShaper::shapeDeep([
            'request_number' => 'رقم طلب النقل', 'status' => 'الحالة',
            'transfer_type' => 'نوع النقل', 'priority' => 'الأولوية',
            'from_branch' => 'من فرع', 'to_branch' => 'إلى فرع',
            'requested_by' => 'طلب بواسطة', 'approved_by' => 'اعتمد بواسطة',
            'shipped_by' => 'شحن بواسطة', 'received_by' => 'استلم بواسطة',
            'th_missing' => 'مفقود', 'th_damaged' => 'تالف',
            'th_received_qty' => 'الكمية المستلمة', 'th_shipped_qty' => 'الكمية المشحونة',
            'th_approved_qty' => 'الكمية المعتمدة', 'th_requested_qty' => 'الكمية المطلوبة',
            'th_unit_cost' => 'تكلفة الوحدة', 'th_unit' => 'الوحدة', 'th_product' => 'المنتج',
            'no_items' => 'لا توجد أصناف',
            'reference_value' => 'القيمة المرجعية', 'notes' => 'ملاحظات', 'currency' => 'ج.م',
            'sig_shipper' => 'توقيع الشاحن', 'sig_receiver' => 'توقيع المستلم',
        ]);

        $items = $transfer->items->map(fn ($item) => [
            'product_name' => $item->product->name ?? '',
            'sku' => $item->product->sku ?? '',
            'unit' => $item->unit ?? ($item->product->scalar ?? ''),
            'unit_cost' => round((float) ($item->product->purchase_cost ?? 0), 2),
            'requested_quantity' => round((float) $item->requested_quantity, 3),
            'approved_quantity' => $item->approved_quantity !== null ? round((float) $item->approved_quantity, 3) : null,
            'shipped_quantity' => round((float) $item->batches->sum('quantity_shipped'), 3),
            'received_quantity' => $item->received_quantity !== null ? round((float) $item->received_quantity, 3) : null,
            'damaged_quantity' => $item->damaged_quantity !== null ? round((float) $item->damaged_quantity, 3) : null,
            'missing_quantity' => $item->missing_quantity !== null ? round((float) $item->missing_quantity, 3) : null,
        ]);

        $company = CompanySetting::current();
        $companyDisplay = ArabicShaper::companyDisplay($company);

        $qrSvg = QrCode::format('svg')->size(110)->margin(0)->generate($transfer->internalInvoice->invoice_number);
        $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        // Phase 5.3 — Transfer Type derived from is_emergency + WarehouseResolver, never a stored/duplicated field.
        // Wording matches exactly what every transfer report screen uses (TransferReportController)
        // so the same transfer never reads differently on the PDF vs. in a report/table.
        $transferType = $transfer->is_emergency
            ? 'نقل طارئ'
            : (($this->warehouse->isWarehouse($transfer->source_shop_id) || $this->warehouse->isWarehouse($transfer->destination_shop_id))
                ? 'المستودع إلى فرع'
                : 'فرع إلى فرع');

        $data = [
            'company' => $company,
            'companyDisplay' => $companyDisplay,
            'qrDataUri' => $qrDataUri,
            'invoice' => $transfer->internalInvoice,
            // Part 5.8 — the invoice's real, non-editable generation moment: Eloquent
            // stamps created_at automatically at INSERT time inside generateInternalInvoice()
            // (never accepts user input), so this is always the actual system date+time,
            // never a backdated or manually-entered value.
            'generatedAt' => $transfer->internalInvoice->created_at->format('Y-m-d H:i'),
            'transfer' => $transfer,
            'statusLabel' => ArabicShaper::shape($statusLabels[$transfer->status] ?? $transfer->status),
            'priorityLabel' => ArabicShaper::shape($priorityLabels[$transfer->priority] ?? $transfer->priority),
            'transferType' => ArabicShaper::shape($transferType),
            'sourceShopName' => ArabicShaper::shape($transfer->sourceShop->name ?? ''),
            'destinationShopName' => ArabicShaper::shape($transfer->destinationShop->name ?? ''),
            'requestedByName' => ArabicShaper::shape($transfer->requestedByUser->name ?? ''),
            'approvedByName' => ArabicShaper::shape($transfer->approvedByUser->name ?? '—'),
            'shippedByName' => ArabicShaper::shape($shippedByLog->user->name ?? '—'),
            'receivedByName' => ArabicShaper::shape($receivedByLog->user->name ?? '—'),
            'notes' => ArabicShaper::shape($transfer->notes),
            'items' => ArabicShaper::shapeDeep($items->toArray()),
            'title' => ArabicShaper::shape('فاتورة نقل داخلية'),
            'internalOnlyNote' => ArabicShaper::shape('مستند داخلي فقط — لا يُستخدم كفاتورة بيع ولا يظهر في تقارير المبيعات أو الإيرادات'),
            'labels' => $labels,
        ];

        $pdf = Pdf::loadView('branch-operations.internal-transfer-invoice', $data)->setPaper('a4', 'portrait');
        ArabicPdfFont::apply($pdf);
        PdfPageFooter::apply($pdf);

        return $pdf->download("{$transfer->internalInvoice->invoice_number}.pdf");
    }
}
