<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Supply;
use App\Modules\BranchOperations\Models\InternalTransferInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A single, admin-only timeline of EVERY invoice-like document in the
 * system, regardless of which module owns it — sales invoices (customer),
 * purchase invoices (Supply, supplier), and internal transfer invoices
 * (branch-to-branch/warehouse stock movement). None of these three tables
 * are unioned at the SQL level (they don't share a schema); each is queried
 * on its own, normalized into one common shape, then merged/sorted/paginated
 * here in PHP. Every row links back to its own existing detail screen —
 * this page is a lens over the three, not a fourth data store.
 */
class AdminAllInvoicesController extends Controller
{
    public function index(Request $request)
    {
        $type     = $request->get('type', 'all'); // all | sale | purchase | internal_transfer
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');
        $perPage  = min((int) $request->get('per_page', 25), 100);
        $search   = trim((string) $request->get('search', ''));

        $rows = new Collection();

        if ($type === 'all' || $type === 'sale') {
            $rows = $rows->merge($this->salesRows($dateFrom, $dateTo));
        }
        if ($type === 'all' || $type === 'purchase') {
            $rows = $rows->merge($this->purchaseRows($dateFrom, $dateTo));
        }
        if ($type === 'all' || $type === 'internal_transfer') {
            $rows = $rows->merge($this->internalTransferRows($dateFrom, $dateTo));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function ($row) use ($needle) {
                return str_contains(mb_strtolower((string) $row['number']), $needle)
                    || str_contains(mb_strtolower((string) ($row['party'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($row['party_phone'] ?? '')), $needle);
            });
        }

        $rows = $rows->sortByDesc('timestamp')->values();

        $total       = $rows->count();
        $currentPage = max(1, (int) $request->get('page', 1));
        $items       = $rows->forPage($currentPage, $perPage)->values();

        return response()->json(['message' => 'ok', 'data' => [
            'data'         => $items,
            'current_page' => $currentPage,
            'last_page'    => (int) max(1, ceil($total / $perPage)),
            'per_page'     => $perPage,
            'total'        => $total,
            'summary'      => [
                'sales_total'              => $rows->where('type', 'sale')->sum('amount'),
                'purchases_total'          => $rows->where('type', 'purchase')->sum('amount'),
                'internal_transfers_total' => $rows->where('type', 'internal_transfer')->sum('amount'),
                'counts' => [
                    'sale'              => $rows->where('type', 'sale')->count(),
                    'purchase'          => $rows->where('type', 'purchase')->count(),
                    'internal_transfer' => $rows->where('type', 'internal_transfer')->count(),
                ],
            ],
        ]]);
    }

    private function salesRows(?string $from, ?string $to): Collection
    {
        $q = Invoice::with([
            'customer:id,name,phone', 'seller:id,name', 'shop:id,name',
            'payments:id,invoice_id,processing_fee_amount',
            'items:id,invoice_id,product_id,goods_id,quantity,price',
            'items.goods.supplyItem:id,unit_price',
            'items.product:id,purchase_cost',
        ])->latest('id');
        if ($from) $q->whereDate('date', '>=', $from);
        if ($to)   $q->whereDate('date', '<=', $to);

        return $q->get()->map(function (Invoice $inv) {
            $bankFee   = (float) $inv->payments->sum('processing_fee_amount');
            $totalCost = (float) $inv->items->sum('line_cost');

            return [
                'type'         => 'sale',
                'type_label'   => 'فاتورة بيع',
                'id'           => $inv->id,
                'number'       => 'INV-' . $inv->id,
                'date'         => optional($inv->date)->toDateString(),
                'time'         => optional($inv->created_at)->toDateTimeString(),
                'timestamp'    => optional($inv->created_at ?? $inv->date)->timestamp,
                'party'        => $inv->customer?->name ?? 'زبون عابر',
                'party_phone'  => $inv->customer?->phone,
                'branch'       => $inv->shop?->name,
                'created_by'   => $inv->seller?->name,
                'amount'       => (float) $inv->total_amount,
                'status'       => $inv->status,
                'status_label' => ['pending' => 'قيد المراجعة', 'approved' => 'مقبولة', 'cancelled' => 'ملغاة'][$inv->status] ?? $inv->status,
                'is_cancelled' => $inv->status === 'cancelled',
                'link'         => "/dashboard/invoices/{$inv->id}",
                // Report-only fields — never printed on the invoice itself.
                'bank_fee'     => round($bankFee, 2),
                'total_cost'   => round($totalCost, 2),
                'total_profit' => round(((float) $inv->total_amount) - $totalCost, 2),
            ];
        });
    }

    private function purchaseRows(?string $from, ?string $to): Collection
    {
        $q = Supply::with(['supplier:id,name'])->latest('id');
        if ($from) $q->whereDate('date', '>=', $from);
        if ($to)   $q->whereDate('date', '<=', $to);

        return $q->get()->map(fn (Supply $s) => [
            'type'         => 'purchase',
            'type_label'   => 'فاتورة شراء',
            'id'           => $s->id,
            'number'       => $s->invoice_number,
            'date'         => optional($s->date)->toDateString(),
            'time'         => optional($s->created_at)->toDateTimeString(),
            'timestamp'    => optional($s->created_at ?? $s->date)->timestamp,
            'party'        => $s->supplier?->name,
            'branch'       => 'المستودع الرئيسي',
            'created_by'   => null,
            'amount'       => (float) $s->total_amount,
            'status'       => $s->is_cancelled ? 'cancelled' : 'active',
            'status_label' => $s->is_cancelled ? 'ملغاة' : 'نشطة',
            'is_cancelled' => $s->is_cancelled,
            'link'         => "/dashboard/stock/supplies/show/{$s->id}",
        ]);
    }

    private function internalTransferRows(?string $from, ?string $to): Collection
    {
        $q = InternalTransferInvoice::with(['sourceShop:id,name', 'destinationShop:id,name', 'user:id,name'])->latest('id');
        if ($from) $q->whereDate('date', '>=', $from);
        if ($to)   $q->whereDate('date', '<=', $to);

        return $q->get()->map(fn (InternalTransferInvoice $t) => [
            'type'         => 'internal_transfer',
            'type_label'   => 'فاتورة نقل داخلي',
            'id'           => $t->id,
            'number'       => $t->invoice_number,
            'date'         => optional($t->date)->toDateString(),
            'time'         => optional($t->created_at)->toDateTimeString(),
            'timestamp'    => optional($t->created_at ?? $t->date)->timestamp,
            'party'        => ($t->sourceShop?->name ?? '—') . ' ← ' . ($t->destinationShop?->name ?? '—'),
            'branch'       => $t->destinationShop?->name,
            'created_by'   => $t->user?->name,
            'amount'       => (float) $t->reference_value,
            'status'       => $t->status,
            'status_label' => ['active' => 'نشطة', 'cancelled' => 'ملغاة'][$t->status] ?? $t->status,
            'is_cancelled' => $t->status === 'cancelled',
            'link'         => "/dashboard/branch-operations/transfers/{$t->transfer_request_id}",
        ]);
    }
}
